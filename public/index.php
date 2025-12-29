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

$app = AppFactory::create();

$flash = new Messages();

$renderer = new PhpRenderer(__DIR__ . '/../templates');
$renderer->setLayout('layout.phtml');

$renderer->addAttribute('flash', $flash);

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$port = $_ENV['PORT'];

var_dump($port);

$urlStr = $_ENV['DATABASE_URL'] /* ?? getenv('DATABASE_URL') */;

if (!$urlStr) {
    die("Ошибка: DATABASE_URL не найдена. Проверьте настройки Environment в Render.");
}

$databaseUrl = parse_url($urlStr);

var_dump($databaseUrl);

$conStr = sprintf(
    "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
    $databaseUrl['host'],
    $databaseUrl['port'] /* ?? 5432 */,
    ltrim($databaseUrl['path'], '/'),
    $databaseUrl['user'],
    $databaseUrl['pass']
);

$pdo = new \PDO($conStr);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

$checkTable = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'urls' LIMIT 1");

if ($checkTable === false || $checkTable->fetchColumn() === false) {
    $sqlPath = __DIR__ . '/../database.sql'; 
    if (file_exists($sqlPath)) {
        $sql = file_get_contents($sqlPath);
        $pdo->exec($sql);
    }
}

$app->get('/', function ($request, $response) use ($renderer) {
    return $renderer->render($response, "home.phtml", [
        'url' => ['name' => ''],
        'errors' => []
    ]);
});

$app->get('/urls', function ($request, $response) use ($pdo, $renderer) {
    $stmt = $pdo->query("SELECT id, name FROM urls ORDER BY created_at DESC");
    $urls = $stmt->fetchAll();

    return $renderer->render($response, 'urls/index.phtml', ['urls' => $urls]);
})->setName('urls.index');

$app->post('/urls', function ($request, $response) use ($pdo, $flash, $renderer) {
    $data = $request->getParsedBody();

    // echo '<pre>';
    // var_dump($data);
    // echo '</pre>';
    // die();

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

    // echo '<pre>';
    // var_dump($existingUrl);
    // echo '</pre>';
    // die();

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

$app->get('/urls/{id}', function ($request, $response, array $args) use ($pdo, $renderer) {
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

$app->post('/urls/{url_id}/checks', function ($request, $response, array $args) use ($pdo, $flash) {
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

        $h1 = $document->has('h1') ? $document->find('h1')[0]->text() : null;
        $title = $document->has('title') ? $document->find('title')[0]->text() : null;
        
        $descriptionElement = $document->find('meta[name=description]')[0] ?? null;
        $description = $descriptionElement ? $descriptionElement->getAttribute('content') : null;

        $stmt = $pdo->prepare("
            INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        // echo '<pre>';
        // var_dump(Carbon::now('Europe/Moscow'));
        // echo '</pre>';
        // die();

        // var_dump(Carbon::now());
        
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
        $flash->addMessage('danger', 'Проверка завершилась с ошибкой: ' . $e->getMessage());
    }

    $routeContext = RouteContext::fromRequest($request);
    $routeParser = $routeContext->getRouteParser();
    $url = $routeParser->urlFor('urls.show', ['id' => $urlId]);

    return $response->withHeader('Location', $url)->withStatus(302);
});

$app->run();
