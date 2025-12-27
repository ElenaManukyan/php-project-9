<?php

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// $databaseUrlStr = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');

$app = AppFactory::create();

$renderer = new PhpRenderer(__DIR__ . '/../templates');

$app->get('/', function ($request, $response) use ($renderer) {
    return $renderer->render($response, "home.phtml");
});

$app->get('/urls', function ($request, $response) use ($pdo) {
    $stmt = $pdo->query("SELECT id, name FROM urls ORDER BY created_at DESC");
    $urls = $stmt->fetchAll();

    return $this->get('view')->render($response, 'urls/index.phtml', ['urls' => $urls]);
})->setName('urls.index');

$app->post('/urls', function ($request, $response) use ($pdo) {
    $data = $request->getParsedBody();

    // var_dump($data);

    $urlName = $data['url'] ?? '';

    // Нужно изменить эту логику
    $response->getBody()->write("Вы ввели: " . htmlspecialchars($urlName));
    return $response;
})->setName('urls.store');

$app->get('/urls/{id}', function ($request, $response, array $args) use ($pdo) {
    $id = $args['id'];

    $stmt = $pdo->prepare("SELECT * FROM urls WHERE id = ?");
    $stmt->execute([$id]);
    $url = $stmt->fetch();

    if (!$url) {
        return $response->withStatus(404)->write('Страница не найдена');
    }

    $stmtChecks = $pdo->prepare("SELECT * FROM url_checks WHERE url_id = ? ORDER BY created_at DESC");
    $stmtChecks->execute([$id]);
    $checks = $stmtChecks->fetchAll();

    return $this->get('view')->render($response, 'urls/show.phtml', [
        'url' => $url,
        'checks' => $checks
    ]);
})->setName('urls.show');

$urlStr = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
$databaseUrl = parse_url($urlStr);

// var_dump($databaseUrl);

// $databaseUrl = parse_url($_ENV['DATABASE_URL']);

$conStr = sprintf(
    "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
    $databaseUrl['host'],
    $databaseUrl['port'],
    ltrim($databaseUrl['path'], '/'),
    $databaseUrl['user'],
    $databaseUrl['pass']
);

// var_dump($databaseUrl);

$pdo = new \PDO($conStr);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

// try {
//     $stmt = $pdo->query('SELECT version()');
//     $version = $stmt->fetchColumn();
    
//     var_dump("Успешное подключение к БД! Версия: " . $version); 
// } catch (\PDOException $e) {
//     var_dump("Ошибка подключения: " . $e->getMessage());
// }

$app->run();
