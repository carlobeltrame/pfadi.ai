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

function calculateCost($input, $output) {
    $costs = [
        'gpt-4-1106-preview' => [ 'input' => 0.01, 'output' => 0.03 ],
        'gpt-4' => [ 'input' => 0.01, 'output' => 0.03 ],
        'gpt-3-1106-preview' => [ 'input' => 0.01, 'output' => 0.03 ],
        'gpt-3' => [ 'input' => 0.01, 'output' => 0.03 ],
    ];
    $cost = $costs[$_ENV['OPENAI_MODEL_NAME']] ?? $costs['gpt-4-1106-preview'];

    // Calculate usage, because OpenAI does not report usage on streamed responses
    $tokenizer = new Gioni06\Gpt3Tokenizer\Gpt3Tokenizer(new Gioni06\Gpt3Tokenizer\Gpt3TokenizerConfig());
    $inputTokens = $tokenizer->encode($input);
    $outputTokens = $tokenizer->encode($output);
    return count($inputTokens) * $cost['input'] / 1000 + count($outputTokens) * $cost['output'] / 1000;
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
    '16-17_basis' => "Basiskurs {$targetGroup}",
    '17-18_aufbau' => "Aufbaukurs {$targetGroup}",
    '17-18_ef' => "Stufeneinführungskurs {$targetGroup}",
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
    '16-17_basis' => "Die Kursteilnehmenden sind zwischen 16 und 17 Jahren alt und werden zu verantwortungsbewussten Mitleitenden in einem Lagerleitungsteam für {$targetGroupDescription} ausgebildet.",
    '17-18_aufbau' => "Die Kursteilnehmenden sind zwischen 17 und 18 Jahren alt und werden zu Hauptlagerleitenden in Lagern für {$targetGroupDescription} ausgebildet.",
    '17-18_ef' => "Die Kursteilnehmenden sind zwischen 17 und 18 Jahren alt, haben bereits eine Pfadileitungs-Ausbildung und werden im Kurs auf die spezifischen Bedürfnisse von {$targetGroupDescription} geschult.",
    '19-24_panorama' => 'Die Kursteilnehmenden sind zwischen 19 und 24 Jahren alt und ihre offene Betrachtungsweise sowie die Fähigkeit, kritisch zu reflektieren sollen gefördert werden.',
    '21-27_top' => 'Die Kursteilnehmenden sind zwischen 21 und 27 Jahren alt und werden dazu ausgebildet, Pfadi-Ausbildungskurse zu planen und durchzuführen, Ausbildungsblöcke didaktisch sinnvoll zu gestalten und fördernde Rückmeldegespräche zu führen.',
];
$ageAndCourseGoal = $agesAndCourseGoals[$_GET['age_group']] ?? $agesAndCourseGoals['16-17_basis'];

$examples = [
    '16-17_basis' => [
        'with_motto' => "Blocktitel: Stufengerechtigkeit und Bedürfnisse
Kurs-Einkleidung: Harry Potter
Ausbildungsinhalte: Pfadistufengerechtigkeit, Aktivitäten für Pfadistufe anpassen
Blockziele:
- Die TN können Aktivitätsbeispiele nach Tauglichkeit für die Pfadistufe beurteilen
- Die TN können Aktivitätsbeispiele für die Pfadistufe anpassen.

Ausbildungsblock zu diesem Blocktitel und diesen Blockzielen:
### Ausrichten:
Der Sortierhut macht sich Sorgen, weil die Lehrpersonen in Hogwarts sich überlegen, Kinder schon ab 7 Jahren aufzunehmen. Aber der Sortierhut hat bisher immer nur 11jährige Kinder sortiert, und hat keine Ahnung ob er 7jährige Kinder überhaupt richtig einschätzen könnte. Darum nehmen wir die Altersunterschiede und Entwicklung der Kinder ein wenig unter die Lupe.
### Reaktivieren:
Auf einem Packpapier ist ein Zeitstrahl aufgemalt. Auf diesem Zeitstrahl durchläuft ein Kind (mit Bildli :) ) das Alter von 5 bis 20. Die TN sollen je einen Strich darauf malen, wann bei ihnen Kinder von den Wölfli zu den Pfadis kommen, und einen Strich, wann die Pfadis zu den Pios gehen oder zu Leitenden
werden.
Die TN bekommen je eine Spielkarte aus einer Auswahl von 4 Kartenfarben und 5 verschiedenen Kartenwerten. Wir teilen die TN anhand ihrer Kartenwerte auf fünf Vierergruppen auf (ev. gibts eine Gruppe mit 5 TN).
Jede Vierergruppe bekommt einen der folgenden Aufträge (d.h. zwei der drei Aufträge werden von je zwei Gruppen bearbeitet):
Wie unterscheiden sich die Kinder in der Pfadistufe von Kindern in der Wolfsstufe?
Wie unterscheiden sich die Kinder in der Pfadistufe von Jugendlichen in der Piostufe?
Wie unterscheiden sich die Kinder in der Pfadistufe von euch (Roverstufe)?
Die TN-Gruppen bekommen dazu je ein Whiteboard mit einem vorbereiteten Placemat (Rechteck in der Mitte, mit Ecken die zu den Whiteboard-Ecken verbunden sind). Die TN sitzen um das Whiteboard herum und schreiben zuerst ihre Ideen einzeln in die Felder am Rand. Dann tragen sie in der Mitte das Wichtigste zusammen.
Wenn alle TN-Gruppen ein Resultat haben, stellen sie es sich gegenseitig im Plenum vor.
### Informieren:
Jede der fünf Gruppen von vorher bekommt einen von 5 Abschnitten aus dem Pfadiprofil über die Eigenschaften der Kinder in der Pfadistufe. Sie sollen diesen Abschnitt durchlesen, und wichtige Punkte markieren oder aufschreiben.
Dann machen wir 4 neue Expertengruppen (anhand der Kartenfarbe neu zusammensetzen). Die TN sollen sich ihre gelesenen Abschnitte vorstellen, und haben den Auftrag, einen Spickzettel (Zauberformel) zusammenzustellen, wie man gutes Programm für die Pfadistufe erkennt.
### Verarbeiten:
Je zwei der vier Gruppen gehen zusammen in einen Raum. Die TN der einen Gruppe bekommen ein Aktivitätsbeispiel von der Kursleitung vorgegeben, und spielen dieses in einem Rollenspiel vor. Die TN der anderen Gruppe schauen zu, beurteilen mithilfe ihrer Zauberformel, ob diese Aktivität pfadistufengerecht ist, und machen Verbesserungsvorschläge, wie die Aktivität angepasst werden könnte.
### Abschliessen:
Wir legen Fantasy-Bilder aus. Die TN sollen sich alle ein Bild aussuchen, und der Reihe nach sagen, was ihr Bild mit dem gerade erlebten Block zu tun hat.",
        'without_motto' => "Blocktitel: SiKo Theorie / sicherheitsrelevante Aktivitäten
Ausbildungsinhalte: Wo benötige ich ein SiKo, was sind sicherheitsrelevante Aktivitäten, Sicherheitsbereiche, Fallbeispiele Unfälle, Sicherheitshilfsmittel wie 3x3
Blockziele:
- Die TN können Aktivitäten im Sicherheitsbereich, sicherheitsrelevante Aktivitäten und \"normale\" Aktivitäten abgrenzen
- Die TN kennen Hilfestellungen zum Schreiben eines Aktivitäten-SiKos

Ausbildungsblock zu diesem Blocktitel und diesen Blockzielen:
### Ausrichten:
Beispiel-Situation: Schlitteltag. Die TN sollen so viele Gefahren wie möglich aufzählen, die an so einer Aktivität auftreten können. Wir schreiben die Gefahren auf Packpapier auf.
### Reaktivieren:
Wir stellen die TN sortiert nach Anzahl Knochen auf, die sie sich schon gebrochen haben. Dann nummerieren wir auf sechs.
In diesen Gruppen tauschen wir uns aus:
Wie können wir in der Pfadi mit solchen Gefahren umgehen? Wir schreiben in der Planungsphase ein Sicherheitskonzept (SiKo).
Wer hat schon einmal ein SiKo für eine Aktivität geschrieben? Wer für ein ganzes Lager? Nach dem Basis müssen die TN ein SiKo für eine Aktivität schreiben können, aber für ein Lager muss man erst nach dem Aufbau können.
Wie geht man vor, um ein SiKo zu schreiben? In den meisten Fällen schreibt man von früheren Aktivitäten oder Vorlagen ab, und passt es für aktuelle Situation an. Dafür hats in der Broschüre \"Sicherheit\" Vorlagen, von denen man abgucken kann.
Wie ist ein SiKo aufgebaut? Sicherheitsvorkehrungen (präventiv) und Vorgehen im Notfall (reaktiv).
Für welche Aktivitäten brauchts ein SiKo? Kurz Ideen sammeln und folgende ergänzen falls sie nicht genannt wurden: Seilaktivitäten, Pionierbauten, Velofahrten, anspruchsvolle Spiel- und Sportaktivitäten.
Dann verteilen wir das J+S-Merkblatt Unfallprävention (das mit den grünen, orangen und roten Rechtecken für die drei Sicherheitsbereiche).
### Informieren:
Alle in der Gruppe lesen die erste, allgemeine Seite des Merkblatts. Für die restlichen drei Seiten teilen sie sich auf, so dass jeder Sicherheitsbereich von mindestens jemandem gelesen wird.
### Verarbeiten:
Konkrete Situationen werden geschildert und die TN müssen einordnen ob sie a) ohne SiKo möglich sind, b) ein SiKo nötig ist, c) das eine Aktivität im Sicherheitsbereich ist und ein Sicherheitsmodul braucht oder d) die Aktivität gar nicht erlaubt ist in Lagersport/Trekking.
- Versteckis im Wald spielen. Lösung: a)
- Wanderung im SoLa, ein Teil der Strecke ist T3 (nach der SAC-Wanderskala). Lösung: b) oder c), je nach Art und Länge der T3 Stelle
- Hinter dem Lagerhaus in den Bergen ein Iglu bauen und darin übernachten. Lösung: b)
- Bachwanderung. Lösung: b) oder c), je nach Schwierigkeit der Strecke.
- Auf einer Wanderung ein kurzes Stück über einen Gletscher laufen. Lösung: d)
- Mit 15 TN im SoLa in einem See baden gehen, 1 Leiter hat SLRG Brevet See. Lösung: b), aber max. 12 TN dürfen gleichzeitig ins Wasser.
- Nach einer langen Wanderung die Füsse in einen Fluss halten. Lösung: b) und die Stelle sollte gut gewählt sein.
- Fackelwanderung in der Nacht. Lösung: b), je nach Gelände und Länge vielleicht a)
- Überlebensnacht der Pfadis in Kleingruppen ohne Leitende, 300 Meter vom Lagerplatz. Lösung: b), auf Wolfsstufe sind Aktivitäten ohne Leitende zwar verboten (PBS Lagerreglement), auf Pfadistufe aber möglich wenn instruierte Leitpfadis dabei sind und die Lagerleitung immer weiss wo die Gruppen sich befinden.
- Mehrtägige Wanderung mit Übernachtung in SAC-Hütte, auf dem letzten Stück zur Hütte muss man sich an einem Seil festhalten. Lösung: c) weil Wanderstrecken die man nicht mit freien Händen gehen kann mindestens als T3 gelten.
- Mit Pfadigruppe Riverraften gehen. Lösung: d)
Falls noch Zeit ist, können die TN selber noch Situationen für die anderen erfinden. Also jemand der über den Sicherheitsbereich Berg gelesen hat, könnte Situationen erfinden, für die man ein Bergmodul braucht etc.
### Abschliessen:
Letzte Frage: Der Schlitteltag vom Anfang, mit einer Wölfligruppe. Lösung: b), also ein SiKo ist nötig.
Nochmals SiKo-Vorlagen in Sicherheits-Broschüre erwähnen."
    ],
];
$example = $examples[$_GET['age_group']] ?? $examples['16-17_basis'];
$motto = $_GET['motto'] ?? '';
$example = $example[strlen($motto) > 0 ? 'with_motto' : 'without_motto'] ?? $example['without_motto'];
if (is_array($example)) {
    if (count($example) == 1) $example = $example[0];
    $example = join("\n\nWeiteres Beispiel:\n", $example);
}

$title = $_GET['title'];
$contents = $_GET['contents'];
$goals = $_GET['goals'];
$mottoMessage = strlen($motto) > 0 ? "Kurs-Einkleidung: {$motto}\n" : '';
$messages = [
    ['role' => 'system', 'content' => "Schreibe einen Ausbildungsblock (Lektion) für einen Pfadi-Ausbildungskurs. {$ageAndCourseGoal} Vorgegeben sind der Titel des Ausbildungsblocks, sowie die Blockziele (Lernziele) für den Ausbildungsblock. Verwende einen ARIVA-Aufbau (Ausrichten, Reaktivieren, Informieren, Verarbeiten, Abschliessen).

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

{$example}

Schreibe nun das Programm für folgenden Block. Gib ausschliesslich das Programm aus, formatiert wie im Beispiel oben." ],
    ['role' => 'user', 'content' => "Blocktitel: {$title}\n{$mottoMessage}Ausbildungsinhalte: {$contents}\nBlockziele:\n{$goals}\nAusbildungsblock zu diesem Blocktitel und diesen Blockzielen:\n"],
];

$stream = $client->chat()->createStreamed([
    'model' => $_ENV['OPENAI_MODEL_NAME'],
    'messages' => $messages,
    'max_tokens' => 2048,
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
    'motto' => $motto,
    'contents' => $contents,
    'goals' => $goals,
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

$host = $_ENV['MYSQL_HOST'];
$port = $_ENV['MYSQL_PORT'];
$dbname = $_ENV['MYSQL_DATABASE'];
$user = $_ENV['MYSQL_USER'];
$password = $_ENV['MYSQL_PASSWORD'];
if ($host && $dbname && $user && $password) {
    $cost = calculateCost($messages[0]['content'] . "\n" . $messages[1]['content'], $data['message']);
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=UTF8";
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    $pdo = new PDO($dsn, $user, $password, $options);
    $sql = "INSERT INTO kursblock_programme (title, age_group, target_group, motto, contents, goals, programme, cost) VALUES (?,?,?,?,?,?,?,?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data['title'], $data['ageGroup'], $data['targetGroup'], $data['motto'], $data['contents'], $data['goals'], $data['message'], $cost]);
}
