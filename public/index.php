<?php

session_start();

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Slim\Flash\Messages;
use Slim\Exception\HttpNotFoundException;
use Psr\Http\Message\ServerRequestInterface;
use Dotenv\Dotenv;
use Slim\Routing\RouteContext;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Carbon\Carbon;
use DI\Container;
use Symfony\Component\DomCrawler\Crawler;
use Valitron\Validator;

$container = new Container();

$container->set(\PDO::class, function () {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();

    $urlStr = $_ENV['DATABASE_URL'] ?? null;
    if (!$urlStr) {
        die("Ошибка: DATABASE_URL не найдена.");
    }

    $databaseUrl = parse_url($urlStr);
    $conStr = sprintf(
        "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
        $databaseUrl['host'],
        $databaseUrl['port'],
        ltrim($databaseUrl['path'], '/'),
        $databaseUrl['user'],
        $databaseUrl['pass']
    );

    $pdo = new \PDO($conStr);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    return $pdo;
});

AppFactory::setContainer($container);
$app = AppFactory::create();

$flash = new Messages();
$renderer = new PhpRenderer(__DIR__ . '/../templates');
$renderer->setLayout('layout.phtml');

$routeParser = $app->getRouteCollector()->getRouteParser();
$renderer->addAttribute('router', $routeParser);

$app->add(function ($request, $handler) use ($renderer, $flash) {
    $renderer->addAttribute('flashMessages', $flash->getMessages());
    return $handler->handle($request);
});

$renderer->addAttribute('flash', $flash);

$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$app->get('/', function ($request, $response) use ($renderer) {
    return $renderer->render($response, "home.phtml", [
        'url' => ['name' => ''],
        'errors' => []
    ]);
});

$app->get('/urls', function ($request, $response) use ($renderer) {
    $pdo = $this->get(\PDO::class);

    $urls = $pdo->query("SELECT id, name FROM urls ORDER BY created_at DESC")->fetchAll();

    $checksSql = "SELECT DISTINCT ON (url_id) url_id, created_at, status_code 
                  FROM url_checks 
                  ORDER BY url_id, id DESC";
    $checks = $pdo->query($checksSql)->fetchAll();

    $checksById = [];
    foreach ($checks as $check) {
        $checksById[$check['url_id']] = $check;
    }

    $urlsWithChecks = array_map(function ($url) use ($checksById) {
        $lastCheck = $checksById[$url['id']] ?? null;
        return array_merge($url, [
            'last_check' => $lastCheck['created_at'] ?? null,
            'status_code' => $lastCheck['status_code'] ?? null
        ]);
    }, $urls);

    return $renderer->render($response, 'urls/index.phtml', ['urls' => $urlsWithChecks]);
})->setName('urls.index');

$app->post('/urls', function ($request, $response) use ($flash, $renderer) {
    $pdo = $this->get(\PDO::class);
    $data = $request->getParsedBody();
    $urlData = $data['url'] ?? [];
    $urlName = $urlData['name'] ?? '';

    $v = new Validator(['name' => $urlName]);

    $v->rule('required', 'name')->message('URL не должен быть пустым');
    $v->rule('url', 'name')->message('Некорректный URL');
    $v->rule('lengthMax', 'name', 255)->message('URL превышает 255 символов');

    if (!$v->validate()) {
        $errors = $v->errors();
        $nameErrors = $errors['name'] ?? [];
        $firstError = $nameErrors[0] ?? 'Некорректный ввод';

        return $renderer->render($response->withStatus(422), "home.phtml", [
            'url' => ['name' => $urlName],
            'errors' => ['name' => $firstError]
        ]);
    }

    $parsedUrl = parse_url($urlName);
    $scheme = strtolower($parsedUrl['scheme']);
    $host = strtolower($parsedUrl['host']);
    $normalizedUrl = "{$scheme}://{$host}";

    $stmt = $pdo->prepare("SELECT id FROM urls WHERE name = ?");
    $stmt->execute([$normalizedUrl]);
    $existingUrl = $stmt->fetch();

    if ($existingUrl) {
        $id = $existingUrl['id'];
        $flash->addMessage('info', 'Страница уже существует');
    } else {
        $stmt = $pdo->prepare("INSERT INTO urls (name, created_at) VALUES (?, ?)");
        $stmt->execute([$normalizedUrl, date('Y-m-d H:i:s')]);
        $id = $pdo->lastInsertId();
        $flash->addMessage('success', 'Страница успешно добавлена');
    }

    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $url = $routeParser->urlFor('urls.show', ['id' => $id]);

    return $response
        ->withHeader('Location', $url)
        ->withStatus(302);
})->setName('urls.store');

$app->get('/urls/{id:[0-9]+}', function ($request, $response, array $args) use ($renderer) {
    $pdo = $this->get(\PDO::class);
    $id = $args['id'];

    $stmt = $pdo->prepare("SELECT * FROM urls WHERE id = ?");
    $stmt->execute([$id]);
    $url = $stmt->fetch();

    if (!$url) {
        return $response->withStatus(404)->write('Страница не найдена');
    }

    $stmtChecks = $pdo->prepare("SELECT * FROM url_checks WHERE url_id = ? ORDER BY created_at DESC");
    $stmtChecks->execute([$id]);
    $checks = $stmtChecks->fetchAll() ?: [];

    return $renderer->render($response, 'urls/show.phtml', [
        'url' => $url,
        'checks' => $checks
    ]);
})->setName('urls.show');

$app->post('/urls/{url_id:[0-9]+}/checks', function ($request, $response, array $args) use ($flash) {
    $pdo = $this->get(\PDO::class);
    $urlId = $args['url_id'];

    $stmt = $pdo->prepare("SELECT name FROM urls WHERE id = ?");
    $stmt->execute([$urlId]);
    $url = $stmt->fetch();

    if (!$url) {
        return $response->withStatus(404);
    }

    $client = new Client(['timeout' => 5.0, 'verify' => false]);

    try {
        $res = $client->get($url['name']);
        $html = $res->getBody()->getContents();
        $statusCode = $res->getStatusCode();
    } catch (ConnectException | RequestException $e) {
        $flash->addMessage('danger', "Проверка завершилась с ошибкой: Проблема с соединением или запросом");
        
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        return $response->withHeader('Location', $routeParser->urlFor('urls.show', ['id' => $urlId]))->withStatus(302);
    } catch (\Exception $e) {
        $flash->addMessage('danger', "Проверка завершилась с ошибкой: {$e->getMessage()}");

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        return $response->withHeader('Location', $routeParser->urlFor('urls.show', ['id' => $urlId]))->withStatus(302);
    }

    $crawler = new Crawler($html);

    $h1Node = $crawler->filter('h1');
    $h1 = $h1Node->count() > 0 ? $h1Node->first()->text() : null;
    // $h1Node = $crawler->first('h1');
    // $h1 = $h1Node->count() > 0 ? $h1Node->text() : null;

    $titleNode = $crawler->filter('title');
    $title = $titleNode->count() > 0 ? $titleNode->first()->text() : null;

    $descriptionNode = $crawler->filter('meta[name="description"]');
    $description = $descriptionNode->count() > 0 ? $descriptionNode->attr('content') : null;

    $stmt = $pdo->prepare("
        INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $urlId,
        $statusCode,
        $h1,
        $title,
        $description,
        Carbon::now('Europe/Moscow')
    ]);

    $flash->addMessage('success', 'Страница успешно проверена');

    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    return $response->withHeader('Location', $routeParser->urlFor('urls.show', ['id' => $urlId]))->withStatus(302);
})->setName('urls.checks');

$errorMiddleware->setErrorHandler(HttpNotFoundException::class, function (
    ServerRequestInterface $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use (
    $app,
    $renderer
) {
    $response = $app->getResponseFactory()->createResponse();
    return $renderer->render($response->withStatus(404), 'errors/404.phtml');
});

$app->run();
