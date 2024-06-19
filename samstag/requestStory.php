<?php

require_once __DIR__.'/vendor/autoload.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$client = OpenAI::factory()
    ->withApiKey($_ENV['OPENAI_API_KEY'])
    ->withHttpClient(new \GuzzleHttp\Client(['timeout' => 120, 'connect_timeout' => 120]))
    ->make();

function renderSSE($data, $eventName = 'data') {
    $event = [];
    $event[] = sprintf('id: %s', isset($data['id']) ? $data['id'] : str_replace('.', '', uniqid('', true)));
    $event[] = sprintf('retry: %s', 5000);
    $event[] = sprintf('event: %s', $eventName);
    $event[] = sprintf('data: %s', json_encode($data));
    return implode("\n", $event) . "\n\n";
}

function calculateCost($usage) {
    $costs = [
        'gpt-4o' => [ 'input' => 5, 'output' => 15 ],
        'gpt-4-turbo' => [ 'input' => 10, 'output' => 30 ],
        'gpt-4-turbo-2024-04-09' => [ 'input' => 10, 'output' => 30 ],
        'gpt-4-0125-preview' => [ 'input' => 10, 'output' => 30 ],
        'gpt-4-1106-preview' => [ 'input' => 10, 'output' => 30 ],
        'gpt-4' => [ 'input' => 30, 'output' => 60 ],
        'gpt-3.5-turbo-0125' => [ 'input' => 0.5, 'output' => 1.5 ],
        'gpt-3.5-turbo-1106' => [ 'input' => 1, 'output' => 2 ],
        'gpt-3.5-turbo-0613' => [ 'input' => 1.5, 'output' => 2 ],
        'gpt-3.5-turbo-16k-0613' => [ 'input' => 3, 'output' => 4 ],
        'gpt-3.5-turbo-0301' => [ 'input' => 1.5, 'output' => 2 ],
    ];
    $cost = $costs[$_ENV['OPENAI_MODEL_NAME']] ?? $costs['gpt-4-0125-preview'];

    $inputTokens = $usage->promptTokens ?? 0;
    $outputTokens = $usage->completionTokens ?? 0;
    return $inputTokens * $cost['input'] / 1000000 + $outputTokens * $cost['output'] / 1000000;
}

$targetGroups = [
    'biberstufe' => 'Biberstufe',
    'wolfsstufe' => 'Wolfsstufe',
    'pfadistufe' => 'Pfadistufe',
    'gemischt' => 'gemischt Wolfs- und Pfadistufe',
    'piostufe' => 'Piostufe',
];
$targetGroup = $targetGroups[$_GET['target_group']] ?? $targetGroups['gemischt'];

$targetGroupDescriptions = [
    'biberstufe' => 'Kinder zwischen 4 und 7 Jahren',
    'wolfsstufe' => 'Wölfli zwischen 7 und 11 Jahren',
    'pfadistufe' => 'Pfadis zwischen 11 und 15 Jahren',
    'piostufe' => 'Jugendliche zwischen 14 und 16 Jahren',
];
$targetGroupDescription = $targetGroupDescriptions[$_GET['target_group']] ?? $targetGroupDescriptions['gemischt'];

$defaultTimeframes = [
    'biberstufe' => 'Samstagnachmittag 14:00 bis 16:00',
    'wolfsstufe' => 'Samstagnachmittag 14:00 bis 16:30',
    'pfadistufe' => 'Samstagnachmittag 14:00 bis 16:30',
    'piostufe' => 'Samstagnachmittag 14:00 bis 17:00',
];
$timeframe = $_GET['timeframe'] ?? $defaultTimeframes[$_GET['target_group']] ?? $defaultTimeframes['wolfsstufe'];

$examples = [
    'biberstufe' => [
        "Thema: Reh und Hase
Durchführungszeit: Samstagnachmittag 14:00 bis 16:00
Story: Wir treffen einen Hasen und ein Reh. Wir ahmen die Bewegungen dieser Tiere nach und bemerken, dass sie andere Fähigkeiten haben als wir Menschen.",
        "Thema: Unter Wasser
Durchführungszeit: Samstagnachmittag 14:00 bis 16:00
Story: Eine Meerjungfrau hat ihren Schatz verloren. Nach einigen Tauch-Übungen können wir mit ihr unter Wasser gehen und finden dort den Schatz.",
    ],
    'wolfsstufe' => [
        "Thema: Jack Sparrow und der geheime Schatz
Durchführungszeit: Samstagnachmittag 14:00 bis 16:30
Story: Jack Sparrow sucht einen geheimen Schatz. Wir helfen ihm suchen. Unterwegs treffen wir einen Papageien, besiegen einen Gorilla und zeigen einem Piraten unsere Stärke. Am Zielort finden wir mithilfe der Karte den Schatz: Zutaten für Schoggibananen.",
        "Thema: Cowboys
Durchführungszeit: Samstagnachmittag 14:00 bis 16:30
Story: Wir bringen einen gestohlenen Sack Gold zurück zu seinem Besitzer, einem Cowboy. Dann fangen wir noch mit Hilfe des Sheriffs die Gaunerinnen ein, die das Gold gestohlen haben."
    ],
    'pfadistufe' => [
        "Thema: Alchemisten
Durchführungszeit: Samstagnachmittag 14:00 bis 16:30
Story: Die Alchemisten haben unsere Fahne geklaut, und wir müssen sie zurückholen. Mit der Hilfe eines abtrünnigen Alchemisten finden und sabotieren wir das Ritual der Alchemisten, welche unsere Fahne zu Gold verarbeiten wollen.",
        "Thema: Hammerschmied
Durchführungszeit: Samstagnachmittag 14:00 bis 16:30
Story: Wir helfen einem Schmied, dessen Hammer geklaut wurde. Unterwegs finden wir heraus, dass der Hammer eingeschmolzen wurde, und stellen einen neuen Hammer her. Schliesslich stellt sich heraus dass der Schmied ein Betrüger war, und wir geben den Hammer dem echten Schmied."
    ],
];
$example = $examples[$_GET['target_group']] ?? $examples['wolfsstufe'];
if (is_array($example)) {
    if (count($example) == 1) $example = $example[0];
    $example = join("\n\nWeiteres Beispiel:\n", $example);
}

$title = $_GET['title'];
$messages = [
    ['role' => 'system', 'content' => "Schreibe eine Story für eine Pfadiaktivität für {$targetGroupDescription}.
Die Story sollte maximal 2 Abschnitte lang sein, sollte in sich abgeschlossen sein und realistisch durchführbar sein.

Beispiel:
{$example}

Schreibe nun eine Story zu folgendem Thema. Gib ausschiesslich den Story-Text aus, wie im Beispiel oben. Wiederhole nicht das Thema und lass auch das Prefix \"Story:\" weg." ],
    ['role' => 'user', 'content' => "Thema: {$title}\nDurchführungszeit: {$timeframe}\nStory:"],
];

$stream = $client->chat()->createStreamed([
    'model' => $_ENV['OPENAI_MODEL_NAME'],
    'messages' => $messages,
    'max_tokens' => 512,
    'stream_options' => [ 'include_usage' => true ],
]);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$data = [
    'message' => '',
    'finished' => false,
    'title' => $title,
    'targetGroup' => $targetGroup,
    'timeframe' => $timeframe,
    'uuid' => uniqid(),
    'date' => date("Y-m-d H:i:s"),
];
$usage = null;
foreach ($stream as $result) {
    $usage = $result?->usage;
    if ($newContent = ($result['choices'][0]['delta']['content'] ?? false)) {
        $data['message'] .= str_replace('ß', 'ss', $newContent);
    }
    if ($result['choices'][0]['finish_reason'] !== null) {
        $data['finished'] = true;
    }
    echo renderSSE($data);
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

$host = $_ENV['MYSQL_HOST'];
$port = $_ENV['MYSQL_PORT'];
$dbname = $_ENV['MYSQL_DATABASE'];
$user = $_ENV['MYSQL_USER'];
$password = $_ENV['MYSQL_PASSWORD'];
if ($host && $dbname && $user && $password) {
    $cost = calculateCost($usage);
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=UTF8";
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    $pdo = new PDO($dsn, $user, $password, $options);
    $sql = "INSERT INTO samstag_stories (title, target_group, timeframe, story, cost) VALUES (?,?,?,?,?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data['title'], $data['targetGroup'], $data['timeframe'], $data['message'], $cost]);
}
