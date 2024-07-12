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

$motto = $_GET['motto'] ?? 'keiner (einfach nur die Sportart spielerisch behandeln)';
$timeframe = $_GET['timeframe'];

$examples = [
    'wolfsstufe' => [
        "Sportart: Mattenlauf
Durchführungszeit: Herbstlager, 16:00 bis 16:45
Story-Kontext: Regenwurm und Chihuahua
Programm:
16:00 - 16:05 Die TN hören von einen laut und nervig kläffenden Chihuahua und besammeln sich auf der Wiese vor dem Haus. Dort sehen wir, was die Ursache für das wütende Kläffen ist. Der Chihuahua streitet lauthals mit dem Regenwurm; beide behaupten, der Sportlichere von Beiden zu sein. Weil sie sich nicht einigen können, schlagen wir vor, sich in ihrem Lieblingsspiel (Mattenlauf) zu messen. 
16:05 - 16:15 Der Regenwurm findet die Ausgangslage unfair, da er vom Winterschlaf völlig eingerostet ist. Wir machen deshalb ein Einwärmen: Wir machen 2 Gruppen, die sich untereinander nochmals in 2 Gruppen teilen. Die 2 Teile der Gruppen stehen sich jeweils mit ca. 8m Abstand gegenüber. Der eine Teil hat einen Ball. Der andere Teil viele Karteikarten vor sich, auf welchen jeweils ein Tier steht. Beim Anpfiff spielt die vorderste Person vom Teil mit dem Ball der Person gegenüber den Ball zu. Diese fängt ihn, gibt ihn der Person hinter ihr unter den Beinen weiter, nimmt ein Zettelchen und rennt wie das Tier, das auf der Karteikarte steht auf die andere Seite und stellt sich dort hinten an. Die Person, die jetzt den Ball hat wirft nun ihrerseits den Ball wieder auf die andere Seite. Dies machen wir so lange, bis alle zweimal wie ein Tier gerannt sind. Die Gruppe, bei welcher zuerst alle fertig sind, sitzt hin und hat gewonnen.
16:15 - 16:40 Jetzt sind wir bereit für den Mattenlauf. Wir machen 2 Gruppen. Wir haben 4 Blachen, welche man nach dem Wurf beim Rennen berühren muss. Weil der Chihuahua gerne hoch springt, muss man zwischen der zweiten und dritten Blache über eine Hürde springen. Damit es fair bleibt, darf der Regenwurm auch eine Challange wählen. Zwischen der dritten und letzten Blache muss man deshalb seitlich rollen. Das Team in der Mitte darf die rennenden TN abschiessen. Man kann nur Punkte erzielen, wenn man die ganze Runde schafft, ohne dass der Ball zuerst retourniert wird. Wenn das Ganze zu einfach ist, versuchen wir es noch rückwärts.
16:40 - 16:45 Nach einer gewissen Zeit müssen wir leider aufhören. Der Chihuahua und der Regenwurm fanden es beide so lustig, dass sie ihrem Streit komplett vergessen haben. Um die wiederbelebte Freundschaft zu feiern, machen wir ein Kreissitzen: Wir stehen in einem engen Kreis, und alle drehen sich nach rechts. Dann sitzen alle gleichzeitig vorsichtig auf die Knie der Person hinter sich. Ziel ist, dass alle sitzen können ohne umzufallen. Der Chihuahua und der Regenwurm verabschieden sich überglücklich.

Vollständiges benötigtes Material für dieses Programm:
- Verkleidungen Chihuahua und Regenwurm
- 2 Bälle
- Karteikarten mit Tieren drauf
- Blachen als Matten
- Reifen oder andere Zielmarkierung
- Trinkwasser für zwischendurch",
    ],
    'pfadistufe' => [
        "Sportart: Blachenvolleyball
Durchführungszeit: Sommerlager, 14:00 bis 14:45
Story-Kontext: Dumbo
Programm:
14:00 - 14:05 Wir haben uns gerade draussen versammelt, als ein sehr demotiviert wirkender Dumbo vorbeitrottet. Wir fragen ihn natürlich, was los ist, und er erzählt uns, dass er nicht mehr fliegen kann, was ihn verständlicherweise sehr traurig macht. Wir beschliessen, ihm zu helfen.
14:05 - 14:15 Wir spielen ein British Bulldoggen mit ihm, um ihn erst einmal etwas zu stärken. Dafür stellen sich alle in einer Reihe auf, Dumbo stellt sich gegenüber auf die andere Seite der Wiese. Das Spiel funktioniert wie Wer-hat-Angst-vor-dem-weissen-Hai, aber um jemanden zu fangen muss man die Person 3 Sekunden lang aufheben.
14:15 - 14:35 Da das sehr gut funktioniert hat und fehlende Stärke anscheinend nicht das Problem ist, kommen wir auf die Idee, dass Dumbo vielleicht einfach Überwindungsangst hat. Dumbo meint, dass das schon möglich ist, er ist schon lange nicht mehr geflogen und der Gedanke wieder hoch über der Erde zu fliegen, macht ihn schon etwas nervös. Um ihm zu zeigen, dass nichts Schlimmes mit fliegenden Dingen passiert, spielen wir zusammen ein Blachenvolleyball. Dazu teilen wir uns in zwei Gruppen auf, welche jeweils eine Hälfte des Volleyballfeldes zur Verfügung haben. Zwei bis vier Personen halten zusammen eine Blache, der Volleyball darf nur mit den Blachen geworfen und gefangen werden. Wenn er den Boden auf dem eigenen Feld berührt, bekommt die andere Gruppe einen Punkt.
14:35 - 14:45 Schliesslich scheint Dumbo überzeugt, dass beim Fliegen nichts Schlimmes passiert. Zum Schluss möchte er aber noch gerne ein fliegender Holländer (Hotelfangis) spielen, bei welchem er den Flugstart und die Landung noch repetieren kann. Dafür legen sich alle ausser zwei Personen über die Wiese verteilt auf den Bauch, immer zwei Personen nebeneinander. Eine der noch stehenden Personen muss die andere fangen. Die fliehende Person kann sich neben ein Pärchen legen, dann wird die Person am anderen Ende des Trios zur fangenden Person, und der die Person, die zuvor fangen musste, muss nun flüchten. Nach diesem Spiel fühlt sich Dumbo top vorbereitet, um endlich wieder zu fliegen und verabschiedet sich dankend von uns, um sein Lieblingsfluggebiet aufzusuchen.

Vollständiges benötigtes Material für dieses Programm:
- Verkleidung Dumbo
- Volleyballnetz + Spielfeldmarkierung
- 1-2 Volleybälle
- Blachen
- Trinkwasser für zwischendurch",
    ],
    'piostufe' => [
        "Sportart: Räuber und Bulle
Durchführungszeit: Sommerlager, 14:00 bis 15:00
Story-Kontext: Polizeitraining
Programm:
14:00 - 14:05 Einstieg: Wir besammeln uns und alle sind sprtlich gekleidet. Wir laufen in den Wald und auf dem Weg machen wir ein Bockhüpfen. Das heisst wir laufen hintereinander und die vorderste Person macht ein Böckli. Dann hüpft die hintere Person darüber und macht auch ein Böckli. So geht es weiter bis alle ein Böckli sind. Dann beginnt die hinterste Person und steht auf. So alles rückwärts bis alle wieder stehen.
14:05 - 14:25 Hauptteil: Wir bestimmen die Gruppen fürs Räuber und Bulle mit \"böckli böckli wer isch das\". Die Räuber bekommen 5 Minuten, um sich im abgemachten Spielfeld zu verstecken. Die Bullen dürfen danach anfangen zu suchen. Gefangen wird durch 3maliges Klopfen auf den Rücken. Wer gefangen wird, kommt ins Gefängnis. Von dort kann man befreit werden, wenn ein freier Räuber den Gefangenen 3 Mal auf den Rücken klopft. Nach 10 Minuten Spielzeit oder beim Gewinnen der Bullen werden die Gruppen gewechselt. Wenn die Spielzeit vergeht ohne dass die Bullen alle fangen konnten, gewinnen die Räuber.
14:25 - 14:45 Hauptteil zweite Runde: Für die zweite Runde überlegen wir uns, wie wir das Spiel variieren könnten. Hier einige Vorschläge: Anzahl Räuber/Bullen verändern, Schitli-Verbannis, Zugang zum Gefängnis erschweren (Absperrband, Hindernisse, Wächter*in), Walkie-Talkies
14:45 - 15:00 Ausklang: Wir laufen in lockerem Joggen zum Lagerplatz zurück und beenden dort den Block mit einem Hua, damit die Verlierer*innen ihren Frust rauslassen können.

Vollständiges benötigtes Material für dieses Programm:
- Stirnbänder o.ä. um Teams zu markieren
- Absperrband um Spielfeld und Gefängnis zu markieren
- Walkie-Talkies
- Apotheke
- Trinkwasser für zwischendurch",
    ],
];
$example = $examples[$_GET['target_group']] ?? $examples['pfadistufe'];
if (is_array($example)) {
    $example = join("\n\nWeiteres Beispiel:\n", $example);
}

$topic = $_GET['topic'];
$scaffold = $_GET['scaffold'];
$programme = $_GET['programme'];
$messages = [
    ['role' => 'system', 'content' => "Schreibe eine Materialliste für einen Sportblock in einem J+S-Pfadilager für {$targetGroupDescription}. Vorgegeben sind die Sportart sowie das Programm der Aktivität.
Die Materialliste sollte möglichst vollständig sein, und falls sinnvoll auch eine Apotheke oder ähnliches enthalten.

Beispiel:
{$example}

Liste nun alles nötige Material für folgendes Programm auf. Gib ausschliesslich das Material als Aufzählung aus, wie im Beispiel oben." ],
    ['role' => 'user', 'content' => "Sportart: {$topic}\nDurchführungszeit: {$timeframe}\nProgramm:\n{$programme}\n\nVollständiges benötigtes Material für dieses Programm:\n"],
];

$stream = $client->chat()->createStreamed([
    'model' => $_ENV['OPENAI_MODEL_NAME'],
    'messages' => $messages,
    'max_tokens' => 2048,
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
    'scaffold' => $scaffold,
    'programme' => $programme,
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
    $sql = "INSERT INTO ls_material (topic, target_group, motto, timeframe, scaffold, programme, material, cost) VALUES (?,?,?,?,?,?,?,?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data['topic'], $data['targetGroup'], $data['motto'], $data['timeframe'], $data['scaffold'], $data['programme'], $data['message'], $cost]);
}
