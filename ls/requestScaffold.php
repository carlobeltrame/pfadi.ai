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
    'wolfsstufe' => 'Wolfsstufe',
    'pfadistufe' => 'Pfadistufe',
    'piostufe' => 'Piostufe',
];
$targetGroup = $targetGroups[$_GET['target_group']] ?? $targetGroups['pfadistufe'];

$targetGroupDescriptions = [
    'wolfsstufe' => 'Wölfli zwischen 7 und 11 Jahren',
    'pfadistufe' => 'Pfadis zwischen 11 und 15 Jahren',
    'piostufe' => 'Jugendliche zwischen 14 und 16 Jahren',
];
$targetGroupDescription = $targetGroupDescriptions[$_GET['target_group']] ?? $targetGroupDescriptions['pfadistufe'];

$motto = $_GET['motto'] ?? 'keiner (einfach nur die Sportart betreiben)';
$timeframe = $_GET['timeframe'];

$examples = [
    'wolfsstufe' => [
        "Sportart: Mattenlauf
Durchführungszeit: Herbstlager, 16:00 bis 16:45
Story-Kontext: Regenwurm und Chihuahua
Grober Ablauf:
16:00 - 16:05 Story-Einstieg: Der Regenwurm und der Chihuahua streiten sich, wer sportlicher ist.
16:05 - 16:15 Einstieg: Zum Einwärmen machen wir eine Pass- und Rennspiel mit einem Ball.
16:15 - 16:40 Hauptteil: Wir spielen Mattenlauf mit Zusatz-Challenges welche die beiden Tiere vorgeben.
16:40 - 16:45 Abschluss: Um uns wieder zu beruhigen und die Freundschaft zu stärken machen wir ein Kreissitzen (alle sitzen kreisum auf den Knien der Person dahinter).",
        "Sportart: Morgensport
Durchführungszeit: Sommerlager, 08:30 bis 09:00
Story-Kontext: Drachen
Grober Ablauf:
08:30 - 08:35 Einstieg: Zum Einwärmen werfen wir im Kreis einen Ball herum, und versuchen ihn dabei möglichst kurz zu berühren weil es ein Feuerball ist.
08:35 - 08:50 Hauptteil: Wir spielen ein Drachenschwanz-Fangis mit Bändeln im Hosenbund.
08:50 - 09:00 Abschluss: Wir bilden zusammen einen grossen Drachen (Menschen-Schlange), welcher versuchen muss, seinen eigenen Schwanz zu schnappen.",
        "Sportart: Völkerballturnier
Durchführungszeit: Sommerlager, 13:00 bis 15:00
Story-Kontext: Ninjas
Grober Ablauf:
13:00 - 13:05 Story-Einstieg: Unser Ninja-Freund möchte uns in die Ninja-Künste einführen.
13:05 - 13:20 Einstieg: Wir spielen ein Ninja um unsere Gelenke einzuwärmen.
13:20 - 14:00 Hauptteil 1: Aufteilung auf 3 Gruppen, und Völkerballturnier in diesen Gruppen, um die Wurftechnik und die Ausweich-Technik von Ninjas zu üben.
14:00 - 14:10 Trinkpause.
14:10 - 14:45 Hauptteil 2: Zweite Runde Völkerballturnier, diesmal mit Pferdevölk, um die Agilität weiter zu üben.
14:45 - 15:00 Abschluss: Wir spielen zum herunterkommen ein Bombe, um zu üben Hindernisse zu überwinden.",
    ],
    'pfadistufe' => [
        "Sportart: Blachenvolleyball
Durchführungszeit: Sommerlager, 14:00 bis 14:45
Story-Kontext: Dumbo
Grober Ablauf:
14:00 - 14:05 Story-Einstieg: Dumbo kann nicht mehr fliegen, und wir helfen ihm, das wieder zu erlernen.
14:05 - 14:15 Einstieg: Wir spielen ein British Bulldoggen um die Stärke von Dumbo zu testen.
14:15 - 14:35 Hauptteil: Um die Überwindungsangst von Dumbo zu lindern machen wir ein Blachenvolleyball.
14:35 - 14:45 Abschluss: In einem Hotelfangis (fliegender Holländer) üben wir noch das Abheben und Landen.",
        "Sportart: Morgensport
Durchführungszeit: Pfingstlager, 08:00 bis 08:30
Story-Kontext: keiner (einfach nur die Sportart spielerisch behandeln)
Grober Ablauf:
08:00 - 08:10 Einstieg: Wir machen ein Böckligumpe (Bockspringen) um uns einzuwärmen.
08:10 - 08:20 Hauptteil: Variationen von Chum-mit-gang-weg (in Kreis aufstellen, einige müssen darum herum rennen).
08:20 - 08:30 Abschluss: Im Kreis herum zeigt immer jemand eine Yoga- oder Dehnübung, welche alle anderen nachmachen.",
        "Sportart: Leichtathletik
Durchführungszeit: Sommerlager, 15:00 bis 16:30
Story-Kontext: Schottland
Grober Ablauf:
15:00 - 15:05 Story-Einstieg: Ein Schotte möchte uns die Highland Games näherbringen.
15:05 - 15:15 Einstieg: Zuerst machen wir ein Seilspringen bei dem jemand in der Mitte steht und mit dem Seil rotiert. Die Ausgeschiedenen werden nummeriert und so auf faire Teams verteilt. 
15:15 - 15:30 Disziplin 1: Wir beginnen mit Seilziehen.
15:30 - 15:45 Disziplin 2: Als nächstes kommt Baumstammwerfen dran.
15:45 - 16:00 Disziplin 3: Dann machen wir einen Wanderschuhweitwurf.
16:00 - 16:15 Disziplin 4: Zuletzt kommt noch das Wife Carrying dran.
16:15 - 16:30 Abschluss: Rangverkündigung und Klatschspiel im Kreis zum Ausklang.",
    ],
    'piostufe' => [
        "Sportart: Volleyball
Durchführungszeit: Sommerlager, 19:30 bis 20:30
Story-Kontext: keiner (einfach nur die Sportart spielerisch behandeln)
Grober Ablauf:
19:30 - 19:35 Einstieg: Volleyballnetz in möglichst kurzer Zeit aufstellen,
19:35 - 19:50 Hauptteil 1: Blachenvolleyball 
19:50 - 20:00 Schusstechnik trainieren
20:00 - 20:20 Hauptteil 2: Volleyballmatch
20:20 - 20:30 Abschluss: Fangis mit Ball",
        "Sportart: Räuber und Bulle
Durchführungszeit: Sommerlager, 14:00 bis 15:00
Story-Kontext: Polizeitraining
Grober Ablauf:
14:00 - 14:05 Einstieg: Böckligumpe
14:05 - 14:25 Hauptteil: Teams bilden und Räuber und Bulle 
14:25 - 14:45 Hauptteil 2: Zweite Runde Räuber und Bulle mit Zusatzregeln
14:45 - 15:00 Ausklang: Hua",
    ],
];
$example = $examples[$_GET['target_group']] ?? $examples['pfadistufe'];
if (is_array($example)) {
    $example = join("\n\nWeiteres Beispiel:\n", $example);
}

$topic = $_GET['topic'];
$messages = [
    ['role' => 'system', 'content' => "Schreibe einen groben Ablauf für einen Sportblock in einem J+S-Pfadilager für {$targetGroupDescription}.
Ein Lagersportblock (LS) muss mindestens 30 Minuten lang sein, und ist im Normalfall dreiteilig, mit Einstieg, Hauptteil, Abschluss. Bei langen Sportblöcken darf der Hauptteil auch aus mehreren Teilen bestehen.
Die Teilnehmenden müssen während dem Sportblock grösstenteils sportlich betätigt sein. Pausen sind selbstverständlich einzuplanen, wo angebracht. Es soll keine Reflexionsrunde im Sportblock vorhanden sein.
Der erstellte grobe Ablauf des Sportblocks sollte kurz gehalten und verständlich sein, und realistisch in einem Pfadilager mit begrenztem Material und Leitpersonen durchführbar sein.

Beispiel:
{$example}

Schreibe nun einen groben Ablauf für den folgenden Sportblock.
Gib ausschiesslich den Text des groben Ablaufs aus, wie im Beispiel oben. Wiederhole nicht die Metadaten (Sportart, Durchführungszeit, Story-Kontext) und lass auch das Präfix \"Grober Ablauf:\" weg.
Falls die vorgegebenen Daten die Einhaltung der Vorgaben für einen Lagersportblock unmöglich machen, erkläre stattdessen, warum das so ist." ],
    ['role' => 'user', 'content' => "Sportart: {$topic}\nDurchführungszeit: {$timeframe}\nStory-Kontext: {$motto}\nGrober Ablauf:\n"],
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
    'topic' => $topic,
    'targetGroup' => $targetGroup,
    'motto' => $motto,
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
    if (($result['choices'][0]['finish_reason'] ?? null) !== null) {
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
    $sql = "INSERT INTO ls_scaffolds (topic, target_group, motto, timeframe, scaffold, cost) VALUES (?,?,?,?,?,?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data['topic'], $data['targetGroup'], $data['motto'], $data['timeframe'], $data['message'], $cost]);
}
