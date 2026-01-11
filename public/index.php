<?php

session_start();

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Slim\Flash\Messages;
use Dotenv\Dotenv;
use Slim\Routing\RouteContext;
use GuzzleHttp\Client;
use DiDom\Document;
use Carbon\Carbon;
use DI\Container;

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
$renderer->addAttribute('flash', $flash);


$app->get('/', function ($request, $response) use ($renderer) {
    return $renderer->render($response, "home.phtml", [
        'url' => ['name' => ''],
        'errors' => []
    ]);
});

$app->get('/urls', function ($request, $response) use ($renderer) {
    $pdo = $this->get(\PDO::class);
    
    $sql = "SELECT 
                urls.id, 
                urls.name, 
                url_checks.created_at AS last_check, 
                url_checks.status_code 
            FROM urls 
            LEFT JOIN url_checks ON urls.id = url_checks.url_id 
                AND url_checks.id = (
                    SELECT MAX(id) FROM url_checks WHERE url_id = urls.id
                )
            ORDER BY urls.created_at DESC";

    $stmt = $pdo->query($sql);
    $urls = $stmt->fetchAll();

    return $renderer->render($response, 'urls/index.phtml', ['urls' => $urls]);
})->setName('urls.index');

$app->post('/urls', function ($request, $response) use ($flash, $renderer) {
    $pdo = $this->get(\PDO::class);
    
    $data = $request->getParsedBody();

    $url = $data['url']['name'] ?? '';
    $parsedUrl = parse_url($url);

    if (empty($url) || strlen($url) > 255 || !filter_var($url, FILTER_VALIDATE_URL)) {
        return $renderer->render($response->withStatus(422), "home.phtml", [
            'url' => ['name' => $url],
            'errors' => ['name' => 'Некорректный URL']
        ]);
    }

    if (!isset($parsedUrl['scheme']) || !isset($parsedUrl['host'])) {
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    $normalizedUrl = "{$parsedUrl['scheme']}://{$parsedUrl['host']}";

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

    $routeContext = RouteContext::fromRequest($request);
    $routeParser = $routeContext->getRouteParser();

    $url = $routeParser->urlFor('urls.show', ['id' => $id]);

    return $response
        ->withHeader('Location', $url)
        ->withStatus(302);
})->setName('urls.store');

$app->get('/urls/{id}', function ($request, $response, array $args) use ($renderer) {
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

$app->post('/urls/{url_id}/checks', function ($request, $response, array $args) use ($flash) {
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
        $document = new Document($res->getBody()->getContents());

        $h1 = $document->has('h1') ? $document->find('h1')[0]->text() : '';
        $title = $document->has('title') ? $document->find('title')[0]->text() : null;
        $descriptionElement = $document->find('meta[name=description]')[0] ?? null;
        $description = $descriptionElement ? $descriptionElement->getAttribute('content') : null;

        $stmt = $pdo->prepare("
            INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $urlId,
            $res->getStatusCode(),
            mb_strimwidth($h1, 0, 255),
            mb_strimwidth($title, 0, 255),
            $description,
            Carbon::now('Europe/Moscow')
        ]);

        $flash->addMessage('success', 'Страница успешно проверена');
    } catch (\Exception $e) {
        $flash->addMessage('danger', "Проверка завершилась с ошибкой: {$e->getMessage()}");
    }

    $routeContext = RouteContext::fromRequest($request);
    $routeParser = $routeContext->getRouteParser();
    $url = $routeParser->urlFor('urls.show', ['id' => $urlId]);

    return $response->withHeader('Location', $url)->withStatus(302);
});

$app->run();
