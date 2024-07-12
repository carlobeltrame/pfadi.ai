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

$motto = $_GET['motto'] ?? 'keine (einfach nur die Lerninhalte spielerisch behandeln)';
$timeframe = $_GET['timeframe'];

$examples = [
    'wolfsstufe' => [
        "Lerninhalte: Seilkunde und Morsen
Durchführungszeit: Herbstlager, 16:00 bis 17:00
Story-Kontext: Gestrandeter Schiffskapitän
Grober Ablauf:
16:00 - 16:05 Einstieg: Das Schiff eines Schiffskapitäns ist weggetrieben, und er braucht unsere Hilfe.
16:05 - 16:20 Seilkunde: Fakten über Seile und Seilarten lernen anhand einem bewegten Quiz-Spiel.
16:20 - 16:30 Seilpflege: Mit Pantomime erarbeiten wir Positiv- und Negativbeispiele bei der Seilpflege.
16:30 - 16:40 Morsen: Wir zeigen dem Kapitän das Morsealphabet, damit er mit seiner Crew kommunizieren kann.
16:40 - 16:50 Morsen üben: Wir üben das Morsealphabet mit einem Spiel noch besser auswendig. Der Kapitän zieht erfreut davon.",
        "Lerninhalte: Übermittlung, Naturkunde
Durchführungszeit: Sommerlager, 09:00 bis 10:30
Story-Kontext: Pöstler und Mäuse
Grober Ablauf:
09:00 - 09:15 Einstieg und Gruppenaufteilung: Der Pöstler und die zwei Mäuse brauchen beide unsere Hilfe. Wir teilen uns für einen Postenlauf auf 3 Gruppen auf.
09:15 - 09:40 Übermittlungsposten mit dem Pöstler: Gemorste Wörter mit dem Körper darstellen (Punkte + Striche) und entziffern.
09:40 - 10:05 Pflanzen-Posten mit der Maus: Pflanzen-Memory und im Wald nützliche Pflanzen erkennen.
10:05 - 10:30 Tier-Posten mit der anderen Maus: In einem Konfitüreglas ein Glasbiotop machen und Tiere darin beobachten.",
    ],
    'pfadistufe' => [
        "Lerninhalte: Hängematten aus Blachen bauen
Durchführungszeit: Sommerlager, 14:00 bis 14:45
Story-Kontext: Fernseh-Gameshow
Grober Ablauf:
14:00 - 14:05 Einstieg: Die Fernsehmoderatorin braucht Unterkünfte für die Crew, und bittet uns um Hilfe.
14:05 - 14:20 Ausprobieren: Die TN probieren in kleinen Gruppen eine vorgebaute Hängematte nachzubauen.
14:20 - 14:30 Musterbeispiel zeigen: Eine Leitperson zeigt die einzelnen Schritte wie man es vorbildlich macht.
14:30 - 14:40 Verbessern: Die TN können ihre Hängematten mit dem neuen Wissen noch fertigstellen.
14:40 - 14:45 Ausstieg: Hängematten ausprobieren, die Moderatorin bedankt sich.",
        "Lerninhalte: Blachen, Seil, Pflanzenkunde
Durchführungszeit: Pfingstlager, 10:00 bis 11:45
Story-Kontext: Apollon vs. Athena
Grober Ablauf:
10:00 - 10:05 Einstieg: Apollon und Athena wollen herausfinden wer sich besser mit Pfaditechnik auskennt und veranstalten einen Wettbewerb.
10:05 - 10:30 Gruppenaufteilung: Anhand kleiner Challenges (Hölzchen mit Schnur rechtwinklig verbinden) auf zwei durchnummerieren.
10:15 - 10:30 Material suchen: Mit Orientierungs-Challenges das nötige Material für den Wettbewerb an verstreuten Orten zusammensuchen.
10:30 - 11:00 Wissen erwerben: Innerhalb der Halbgruppen beschäftigt sich je ein Drittel mit Blachenzelten, mit Knoten und mit ansässigen Pflanzen + Feuermachen.
11:00 - 11:30 Präsentieren: Das Erlernte und Erreichte wird präsentiert, wo nötig kommentiert und für den Wettbewerb bewertet.
11:30 - 11:45 Abschluss-Challenge: Bei Unfallsituation wo Zeus verletzt wurde abwechslungsweise Handlungsanweisungen geben für Bonuspunkte im Wettbewerb.",
    ],
    'piostufe' => [
        "Lerninhalte: Gewaltprävention
Durchführungszeit: Sommerlager, 17:00 bis 18:15
Grober Ablauf:
17:00 - 17:10 Einstieg: Standortbestimmung zu bisherigen Erfahrungen mit Gewalt, durch bewegtes Soziogramm (mit geschlossenen Augen)
17:10 - 17:20 Thema fassen: Anhand der gestellten Fragen herausfinden worum es in dieser Lageraktivität wohl geht
17:20 - 17:30 Mobbing: Austausch und Abgleich mit Quellen zu Mobbing
17:30 - 17:40 Was ist Gewalt: Anhand von Fallbeispielen die persönlichen Grenzen (was zählt schon als Gewalt) miteinander abgleichen 
17:40 - 17:50 Miteinander reden: Stiller Massagekreis, dann Austausch wie sich das angefühlt hat
17:50 - 17:55 Teambildungsspiel: Mit verbundenen Augen mit einem Seil ein perfektes Quadrat bilden
17:55 - 18:15 Ausstieg: Zwei bisher eher ruhige TN leiten die Gruppe an, ein Lagerfeuer zu machen. Austausch am Feuer",
        "Lerninhalte: Seiltechnik
Durchführungszeit: Pfingstlager, 10:25 bis 12:15
Story-Kontext: Krimidinner
Grober Ablauf:
10:25 - 10:30 Einstieg: Der falsch Angeschuldigte muss Vorkehrungen treffen, um über eine Seilbrücke über den Fluss zu fliehen 
10:30 - 10:50 Basics: Mit dem J+S-Seilbrücken-Merkblatt die einfachsten Knoten üben
10:50 - 11:20 Seil spannen: Anhand dem Gelernten in Kleingruppen 2-3x ein Seil spannen üben
11:20 - 11:45 Einschub Lagerbau: Wenn keine Menschen am Seil hängen, werden andere Knoten eingesetzt. Diese Knoten aus dem Gedächtnis üben
11:45 - 11:55 Challenge: Wieder in den Kleingruppen möglichst schnell ein Seil für eine Seilbrücke spannen
11:55 - 12:15 Abschluss: Mit dem falsch Angeschuldigten eine vorbereitete Seilbrücke (gesichert) überqueren",
    ],
];
$example = $examples[$_GET['target_group']] ?? $examples['pfadistufe'];
if (is_array($example)) {
    $example = join("\n\nWeiteres Beispiel:\n", $example);
}

$topic = $_GET['topic'];
$messages = [
    ['role' => 'system', 'content' => "Schreibe einen groben Ablauf für eine Lageraktivität in einem J+S-Pfadilager für {$targetGroupDescription}.
Eine Lageraktivität muss folgende fünf Regeln zwingend erfüllen, um bei J+S offiziell als Lageraktivität (LA) zu gelten:
- muss mindestens 30min lang sein.
- muss grösstenteils Ausbildungscharakter haben, d.h. die Teilnehmenden (TN) lernen während der LA etwas.
- muss möglichst praxisnah und spielerisch gestaltet sein, kein Frontalunterricht, und nicht einfach nur trocken \"das Gelernte reflektieren\".
- Alle TN müssen aktiv beteiligt sein, niemand darf zu lange unbeschäftigt sein.
- Die Lerninhalte müssen aus einem oder mehreren der folgenden LA-Themenbereiche sein. Achtung: Nicht alle Themen eignen sich für alle Altersstufen! Mögliche Themenbereiche:
  - **Outdoortechniken**: Wanderplanung, Kartenlesen (z. B. NORDA), Krokieren, Hilfsmittel (z. B. Kompass, GPS, Höhenmeter usw.), orientieren im Gelände
  - **Sicherheit**: Sicherheit bei Aktivitäten im Lager, Unfallorganisation und Alarmierung, 1. Hilfe, Sicherheitsüberlegungen
  - **Natur und Umwelt**: Tier- und Pflanzenwelt, Umweltschutz im Lager, Wetter- und Sternkunde, Übermittlungstechniken, Feuer machen
  - **Pioniertechnik**: Biwakbau, Iglubau, Material- und Ausrüstungskunde, Materialpflege, Erstellen und Abbau von Pionierbauten, Seil- und Knotenkunde, Seilbahnen, Seilbrücken, Abseilen
  - **Lagerplatz/Lagerhaus/Umgebung**: Einrichten von Lagerplatz/Lagerhaus/Umgebung, Abbau, Erstellen von Spielplatzeinrichtungen und Sportgeräten
  - **Prävention und Integration**: Aktivitäten, welche der Prävention und der Integration dienen und die Kompetenzen der Teilnehmenden in diesem Bereich fördern

Der erstellte grobe Ablauf der Lageraktivität sollte kurz gehalten und verständlich sein, und realistisch in einem Pfadilager mit begrenztem Material und Leitpersonen durchführbar sein.

Beispiel:
{$example}

Schreibe nun einen groben Ablauf für die folgende Lageraktivität. Vermeide eine einfache Reflexion am Ende des Programms, die TN sollten wenn dann eher durch ein Spiel oder durch die Story zum reflektieren angeregt werden, nicht direkt danach gefragt werden.
Gib ausschiesslich den Text des groben Ablaufs aus, wie im Beispiel oben. Wiederhole nicht die Metadaten (Lerninhalte, Durchführungszeit, Story-Kontext) und lass auch das Präfix \"Grober Ablauf:\" weg.
Falls die vorgegebenen Daten die Einhaltung der 5 Regeln für eine Lageraktivität unmöglich machen, erkläre stattdessen, warum das so ist." ],
    ['role' => 'user', 'content' => "Lerninhalte: {$topic}\nDurchführungszeit: {$timeframe}\nStory-Kontext: {$motto}\nGrober Ablauf:\n"],
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
    $sql = "INSERT INTO la_scaffolds (topic, target_group, motto, timeframe, scaffold, cost) VALUES (?,?,?,?,?,?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data['topic'], $data['targetGroup'], $data['motto'], $data['timeframe'], $data['message'], $cost]);
}
