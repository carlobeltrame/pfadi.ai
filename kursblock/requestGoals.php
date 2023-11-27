<?php

require_once __DIR__.'/vendor/autoload.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$yourApiKey = $_ENV['OPENAI_API_KEY'];
$client = OpenAI::factory()
    ->withApiKey($yourApiKey)
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

$targetGroups = [
    'biberstufe' => 'Biberstufe',
    'wolfsstufe' => 'Wolfsstufe',
    'pfadistufe' => 'Pfadistufe',
    'gemischt' => 'gemischt Wolfs- und Pfadistufe',
    'piostufe' => 'Piostufe',
];
$targetGroup = $targetGroups[$_GET['target_group']] ?? $targetGroups['gemischt'];

$courseTypes = [
    '14-15_pio' => 'Piokurs',
    '15-16_futura' => 'Futurakurs',
    '16-17_basis' => "Basiskurs ${targetGroup}",
    '17-18_aufbau' => "Aufbaukurs ${targetGroup}",
    '17-18_ef' => "Stufeneinführungskurs ${targetGroup}",
    '19-24_panorama' => 'Panoramakurs',
    '21-27_top' => 'Topkurs',
];
$courseType = $courseTypes[$_GET['age_group']] ?? $courseTypes['16-17_basis'];

$targetGroupDescriptions = [
    'biberstufe' => 'Kinder zwischen 4 und 7 Jahren',
    'wolfsstufe' => 'Wölfli zwischen 7 und 11 Jahren',
    'pfadistufe' => 'Pfadis zwischen 11 und 15 Jahren',
    'gemischt' => 'Wölfli zwischen 7 und 11 Jahren und Pfadis zwischen 11 und 15 Jahren',
    'piostufe' => 'Jugendliche zwischen 14 und 16 Jahren',
];
$targetGroupDescription = $targetGroupDescriptions[$_GET['target_group']] ?? $targetGroupDescriptions['gemischt'];

$agesAndCourseGoals = [
    '14-15_pio' => 'Die Kursteilnehmenden sind zwischen 14 und 15 Jahren alt und werden dazu ausgebildet, eigene Projekte umzusetzen und in der Pfadi mehr Verantwortung zu übernehmen.',
    '15-16_futura' => 'Die Kurseilnehmenden sind zwischen 15 und 16 Jahren alt und werden dazu ausgebildet, einfache Pfadiaktivitäten zu planen und umzusetzen, sowie ihre Pfaditechnikkenntnisse zu verbessern.',
    '16-17_basis' => "Die Kursteilnehmenden sind zwischen 16 und 17 Jahren alt und werden zu verantwortungsbewussten Mitleitenden in einem Lagerleitungsteam für ${targetGroupDescription} ausgebildet.",
    '17-18_aufbau' => "Die Kursteilnehmenden sind zwischen 17 und 18 Jahren alt und werden zu Hauptlagerleitenden in Lagern für ${targetGroupDescription} ausgebildet.",
    '17-18_ef' => "Die Kursteilnehmenden sind zwischen 17 und 18 Jahren alt, haben bereits eine Pfadileitungs-Ausbildung und werden im Kurs auf die spezifischen Bedürfnisse von ${targetGroupDescription} geschult.",
    '19-24_panorama' => 'Die Kursteilnehmenden sind zwischen 19 und 24 Jahren alt und ihre offene Betrachtungsweise sowie die Fähigkeit, kritisch zu reflektieren sollen gefördert werden.',
    '21-27_top' => 'Die Kursteilnehmenden sind zwischen 21 und 27 Jahren alt und werden dazu ausgebildet, Pfadi-Ausbildungskurse zu planen und durchzuführen, Ausbildungsblöcke didaktisch sinnvoll zu gestalten und fördernde Rückmeldegespräche zu führen.',
];
$ageAndCourseGoal = $agesAndCourseGoals[$_GET['age_group']] ?? $agesAndCourseGoals['16-17_basis'];

$examples = [
    '16-17_basis' => "Beispiel:
Blocktitel: Einführung SiKo
Blockziele:
Die TN können für vorgegebene Aktivitäten unterscheiden, ob sie sicherheitsrelevant sind, ob sie im Sicherheitsbereich sind, ob sie nicht erlaubt sind oder ob sie kein SiKo benötigen.
Die TN können für eine vorgegebene Aktivität (und Rekkbericht) die ersten zwei Zeilen des 3x3 ausfüllen.
Die TN kennen die SiKo-Vorlagen aus der Sicherheitsbroschüre und können diese für eine konkrete Aktivität anpassen.
Die TN können in eigenen Worten die Anforderungen bei einigen üblichen sicherheitsrelevanten Aktivitäten zusammenfassen.

Weiteres Beispiel:
Blocktitel: Stufengerechtigkeit und Bedürfnisse
Blockziele:
- Die TN können Aktivitätsbeispiele nach Tauglichkeit für die Pfadistufe beurteilen
- Die TN können Aktivitätsbeispiele für die Pfadistufe anpassen.",
    '17-18_aufbau' => "Beispiel:
Blocktitel: SiKo Theorie
Blockziele:
- Die TN kennen die vier Bereiche, über die ein Lager-SiKo Auskunft geben soll: 1. Informationen zum Lager, 2. allgemeine Sicherheitsvorkehrungen, 3. Vorbereitung für das Handeln im Notfall, 4. Liste der sicherheitsrelevanten Aktivitäten
- Die TN Können ein Lager-SiKo schreiben

Weiteres Beispiel:
Blocktitel: Betreuungsnetzwerk für Stufenleitungen
Blockziele:
- Die TN können ihre Funktion und Aufgaben als Stufenleitung beschreiben.
- Die TN können angeben, an welche Instanz im Betreuungsnetzwerk sie sich wenden können.
- Die TN tauschen sich über die verschieden Abteilungsorganisationen aus.",
];
$example = $examples[$_GET['age_group']] ?? $examples['16-17_basis'];
if (is_array($example)) {
    if (count($example) == 1) $example = $example[0];
    $example = join("\n\nWeiteres Beispiel:\n", $example);
}

$title = $_GET['title'];
$messages = [
    ['role' => 'system', 'content' => "Schreibe Blockziele (Lernziele) für einen Ausbildungsblock (Lektion) in einem Pfadi-Ausbildungskurs. ${ageAndCourseGoal}

Übliche Abkürzungen und Begriffe:
Sicherheitskonzept - SiKo
Teilnehmende - TN
Pfadibewegung Schweiz - PBS
Rekognoszieren (Wanderung ohne Kinder testlaufen) - Rekken
Biberstufe - 4-7jährige Kinder
Wolfsstufe - 7-11jährige Kinder
Pfadistufe - 11-15jährige Jugendliche
Piostufe - 14-17jährige Jugendliche
Roverstufe - Junge Erwachsene
Lagersport - LS
Lageraktivität - LA
Sonstiges Lagerprogramm - LP
Pfadiprofil - Broschüre in welcher die gesamten pädagogischen Grundlagen der PBS zusammengefasst sind
Stufenmodell - teilt die Pfadibewegung in fünf Altersstufen ein, was im Pfadiprofil beschrieben ist
Pfadigrundlagen - die allgemeinen pädagogischen Grundlagen der PBS. Beschreiben, wie die ganzheitliche Förderung der Mitglieder der Pfadi erreicht wird: Mit Aktivitäten abgeleitet aus den sieben Pfadimethoden werden die Ziele einer Stufe zu den fünf Pfadibeziehungen gefördert
Stufenprofil - erklärt für jede Stufe die Umsetzung der Pfadigrundlagen, sowie die Ziele und Arbeitsweise der jeweiligen Stufe (aufgrund des jeweiligen Entwicklungsstandes der Kinder/Jugendlichen)
5 (Pfadi-)Beziehungen - 5 Bereiche in denen die PBS ihre Mitglieder fördern will: Beziehung zur Persönlichkeit, zum eigenen Körper, zu den Mitmenschen, zur Umwelt und zur Spiritualität
7 (Pfadi-)Methoden - 7 Kategorien von Pfadiaktivitäten: Persönlichen Fortschritt fördern, Gesetz und Versprechen, Leben in der Gruppe, Rituale und Traditionen, Mitbestimmen & Verantwortung tragen, Draussen leben, Spielen

${example}

Schreibe nun Blockziele zum folgenden Ausbildungsblock:" ],
    ['role' => 'user', 'content' => "Blocktitel: ${title}\nBlockziele:\n"],
];

$stream = $client->chat()->createStreamed([
    'model' => 'gpt-4',
    'messages' => $messages,
    'max_tokens' => 256,
]);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$data = [
    'message' => '',
    'finished' => false,
    'title' => $title,
    'ageGroup' => $_GET['age_group'],
    'targetGroup' => $_GET['target_group'],
    'courseType' => $courseType,
    'uuid' => uniqid(),
    'date' => date("Y-m-d H:i:s"),
];
foreach ($stream as $result) {
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

// Calculate usage, because OpenAI does not report usage on streamed responses
$tokenizer = new Gioni06\Gpt3Tokenizer\Gpt3Tokenizer(new Gioni06\Gpt3Tokenizer\Gpt3TokenizerConfig());
$input = $messages[0]['content'] . "\n" . $messages[1]['content'];
$output = $data['message'];
$inputTokens = $tokenizer->encode($input);
$outputTokens = $tokenizer->encode($output);
$cost = count($inputTokens) * 0.03 / 1000 + count($outputTokens) * 0.06 / 1000;

$host = $_ENV['MYSQL_HOST'];
$port = $_ENV['MYSQL_PORT'];
$dbname = $_ENV['MYSQL_DATABASE'];
$user = $_ENV['MYSQL_USER'];
$password = $_ENV['MYSQL_PASSWORD'];
if ($host && $dbname && $user && $password) {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=UTF8";
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    $pdo = new PDO($dsn, $user, $password, $options);
    $sql = "INSERT INTO kursblock_goals (title, age_group, target_group, goals, cost) VALUES (?,?,?,?,?)";
    $stmt= $pdo->prepare($sql);
    $stmt->execute([$data['title'], $data['ageGroup'], $data['targetGroup'], $data['message'], $cost]);
}
