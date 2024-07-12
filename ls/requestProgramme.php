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
Grober Ablauf:
16:00 - 16:05 Story-Einstieg: Der Regenwurm und der Chihuahua streiten sich, wer sportlicher ist.
16:05 - 16:15 Einstieg: Zum Einwärmen machen wir eine Pass- und Rennspiel mit einem Ball.
16:15 - 16:40 Hauptteil: Wir spielen Mattenlauf mit Zusatz-Challenges welche die beiden Tiere vorgeben.
16:40 - 16:45 Abschluss: Um uns wieder zu beruhigen und die Freundschaft zu stärken machen wir ein Kreissitzen (alle sitzen kreisum auf den Knien der Person dahinter).

Programm:
16:00 - 16:05 Die TN hören von einen laut und nervig kläffenden Chihuahua und besammeln sich auf der Wiese vor dem Haus. Dort sehen wir, was die Ursache für das wütende Kläffen ist. Der Chihuahua streitet lauthals mit dem Regenwurm; beide behaupten, der Sportlichere von Beiden zu sein. Weil sie sich nicht einigen können, schlagen wir vor, sich in ihrem Lieblingsspiel (Mattenlauf) zu messen. 
16:05 - 16:15 Der Regenwurm findet die Ausgangslage unfair, da er vom Winterschlaf völlig eingerostet ist. Wir machen deshalb ein Einwärmen: Wir machen 2 Gruppen, die sich untereinander nochmals in 2 Gruppen teilen. Die 2 Teile der Gruppen stehen sich jeweils mit ca. 8m Abstand gegenüber. Der eine Teil hat einen Ball. Der andere Teil viele Karteikarten vor sich, auf welchen jeweils ein Tier steht. Beim Anpfiff spielt die vorderste Person vom Teil mit dem Ball der Person gegenüber den Ball zu. Diese fängt ihn, gibt ihn der Person hinter ihr unter den Beinen weiter, nimmt ein Zettelchen und rennt wie das Tier, das auf der Karteikarte steht auf die andere Seite und stellt sich dort hinten an. Die Person, die jetzt den Ball hat wirft nun ihrerseits den Ball wieder auf die andere Seite. Dies machen wir so lange, bis alle zweimal wie ein Tier gerannt sind. Die Gruppe, bei welcher zuerst alle fertig sind, sitzt hin und hat gewonnen.
16:15 - 16:40 Jetzt sind wir bereit für den Mattenlauf. Wir machen 2 Gruppen. Wir haben 4 Blachen, welche man nach dem Wurf beim Rennen berühren muss. Weil der Chihuahua gerne hoch springt, muss man zwischen der zweiten und dritten Blache über eine Hürde springen. Damit es fair bleibt, darf der Regenwurm auch eine Challange wählen. Zwischen der dritten und letzten Blache muss man deshalb seitlich rollen. Das Team in der Mitte darf die rennenden TN abschiessen. Man kann nur Punkte erzielen, wenn man die ganze Runde schafft, ohne dass der Ball zuerst retourniert wird. Wenn das Ganze zu einfach ist, versuchen wir es noch rückwärts.
16:40 - 16:45 Nach einer gewissen Zeit müssen wir leider aufhören. Der Chihuahua und der Regenwurm fanden es beide so lustig, dass sie ihrem Streit komplett vergessen haben. Um die wiederbelebte Freundschaft zu feiern, machen wir ein Kreissitzen: Wir stehen in einem engen Kreis, und alle drehen sich nach rechts. Dann sitzen alle gleichzeitig vorsichtig auf die Knie der Person hinter sich. Ziel ist, dass alle sitzen können ohne umzufallen. Der Chihuahua und der Regenwurm verabschieden sich überglücklich.",
        "Sportart: Morgensport
Durchführungszeit: Sommerlager, 08:30 bis 09:00
Story-Kontext: Drachen
Grober Ablauf:
08:30 - 08:35 Einstieg: Zum Einwärmen werfen wir im Kreis einen Ball herum, und versuchen ihn dabei möglichst kurz zu berühren weil es ein Feuerball ist.
08:35 - 08:50 Hauptteil: Wir spielen ein Drachenschwanz-Fangis mit Bändeln im Hosenbund.
08:50 - 09:00 Abschluss: Wir bilden zusammen einen grossen Drachen (Menschen-Schlange), welcher versuchen muss, seinen eigenen Schwanz zu schnappen.

Programm:
08:30 - 08:35 Zum Einwärmen werfen wir im Kreis einen Ball herum. Der Ball ist dabei ein \"Feuerball\", und man darf ihn daher nie lange berühren, sondern muss ihn gleich wieder der nächsten Person weiterwerfen.
08:35 - 08:50 Nun spielen wir ein Drachenschwanz-Fangis. Dazu stecken sich alle einen Bändel (Drachenschwanz) hinten in den Hosenbund. Das Ziel ist es, die Bändel der anderen Personen zu klauen. Wenn man selber keinen Bändel mehr hat, hat man verloren. Nach einer Weile steigern wir das Spiel: Es finden sich alle in Paaren zusammen, und stehen zusammen hintereinander. Nur die hintere Person hat einen Drachenschwanz, und muss die vordere Person jederzeit an den Schultern oder an der Hüfte festhalten. 
08:50 - 09:00 Zum Abschluss bilden wir noch zusammen einen grossen Drachen, als Menschen-Schlange. Das Ziel der vordersten Person ist es, den Schwanz des Drachen zu schnappen (zu berühren). Wenn das geschafft ist, wird die hinterste Person zur vordersten, und so weiter.",
        "Sportart: Völkerballturnier
Durchführungszeit: Sommerlager, 13:00 bis 15:00
Story-Kontext: Ninjas
Grober Ablauf:
13:00 - 13:05 Story-Einstieg: Unser Ninja-Freund möchte uns in die Ninja-Künste einführen.
13:05 - 13:20 Einstieg: Wir spielen ein Ninja um unsere Gelenke einzuwärmen.
13:20 - 14:00 Hauptteil 1: Aufteilung auf 3 Gruppen, und Völkerballturnier in diesen Gruppen, um die Wurftechnik und die Ausweich-Technik von Ninjas zu üben.
14:00 - 14:10 Trinkpause.
14:10 - 14:45 Hauptteil 2: Zweite Runde Völkerballturnier, diesmal mit Pferdevölk, um die Agilität weiter zu üben.
14:45 - 15:00 Abschluss: Wir spielen zum herunterkommen ein Bombe, um zu üben Hindernisse zu überwinden.

Programm:
13:00 - 13:05 Der Ninja kommt zu uns. Es ist nun Zeit, dass wir die Geheimnisse der Ninjas erlernen. Er führt uns durch die verschiedenen Techniken.
13:05 - 13:20 Als erstes müssen wir gelenkig und schnell sein wie ein Ninja. Dafür spielen wir das Spiel Ninja. Alle stehen in einem Kreis. Der Reihe nach im Kreis herum dürfen nun alle eine Bewegung machen. Ziel ist es, via diese Bewegung mit der Hand eine Hand einer anderen Person zu berühren. Schafft man dies, dann ist die berührte Hand zerstört und kann nicht mehr verwendet werden. Hat eine Person beide Hände verloren, ist sie aus dem Spiel. Es ist aber erlaubt, mit ebenfalls einer Bewegung auszuweichen, wenn man angegriffen wird.
13:20 - 14:00 Wir teilen uns auf 3 Gruppen auf, indem wir uns nach Alter aufstellen und auf 3 nummerieren. In diesen Gruppen üben wir die Wurf(stern)technik und die Ausweichtechnik der Ninjas in einem Völkerballturnier. Von jeder spielenden Gruppe geht eine Person in den Himmel und diese darf wieder zurück, sobald jemand aus dem Team getroffen wurde und im Himmel ist. Man darf aus dem Himmel zurück, sobald man jemand von dort aus getroffen hat. Es steht eine Leitperson am Spielrand und hilft den TN, falls sie nicht verstehen, wo sie jetzt hin müssen, wenn sie jemanden getroffen haben oder getroffen wurden.
14:00 - 14:10 Trinkpause.
14:10 - 14:45 Um unsere Kraft und Schnelligkeit zu trainieren machen noch eine zweite Variante: Pferdevölk. Man kann sich befreien, wenn man im Himmel ein anderes Kind auf den Rücken nimmt und so durch das Feld rennt. Die andere Gruppe muss versuchen das Pferd oder den Reiter zu fangen. Dann müssen sie wieder in den Himmel zurück. Sie können nur gefangen und nicht mit dem Ball getroffen werden. Wenn sie es ins eigene Spielfeld geschafft haben, sind beide wieder frei.
14:45 - 15:00 Nun müssen wir aber unsere innere Ruhe wieder finden, und ausserdem Hindernisse wie ein Ninja überwinden können. Daher spielen wir noch ein Bombe. Wir stehen im Kreis. Ein Kind geht in die Mitte und zählt mit geschlossenen Augen still auf 20. Wenn es bei 20 angekommen ist, ruft es laut \"Bombe\". Im Kreis wird unterdessen der Ball herumgegeben. Das Kind welches den Ball hat, wenn die Bombe explodiert, muss absitzen und die Beine gerade nach vorne strecken. Danach wird wieder auf 20 gezählt und die Person neben dem sitzenden Kind muss jeweils über dessen Beine steigen, um den Ball weiterzugeben, und dann wieder zurück. Das Spiel dauert so lange bis nur noch eine Person steht. Wenn eine Person zu langsam ist, dann darf der Ball bei ihr am Platz auf den Boden gelegt werden, bis sie dort angekommen ist.",
    ],
    'pfadistufe' => [
        "Sportart: Blachenvolleyball
Durchführungszeit: Sommerlager, 14:00 bis 14:45
Story-Kontext: Dumbo
Grober Ablauf:
14:00 - 14:05 Story-Einstieg: Dumbo kann nicht mehr fliegen, und wir helfen ihm, das wieder zu erlernen.
14:05 - 14:15 Einstieg: Wir spielen ein British Bulldoggen um die Stärke von Dumbo zu testen.
14:15 - 14:35 Hauptteil: Um die Überwindungsangst von Dumbo zu lindern machen wir ein Blachenvolleyball.
14:35 - 14:45 Abschluss: In einem Hotelfangis (fliegender Holländer) üben wir noch das Abheben und Landen.

Programm:
14:00 - 14:05 Wir haben uns gerade draussen versammelt, als ein sehr demotiviert wirkender Dumbo vorbeitrottet. Wir fragen ihn natürlich, was los ist, und er erzählt uns, dass er nicht mehr fliegen kann, was ihn verständlicherweise sehr traurig macht. Wir beschliessen, ihm zu helfen.
14:05 - 14:15 Wir spielen ein British Bulldoggen mit ihm, um ihn erst einmal etwas zu stärken. Dafür stellen sich alle in einer Reihe auf, Dumbo stellt sich gegenüber auf die andere Seite der Wiese. Das Spiel funktioniert wie Wer-hat-Angst-vor-dem-weissen-Hai, aber um jemanden zu fangen muss man die Person 3 Sekunden lang aufheben.
14:15 - 14:35 Da das sehr gut funktioniert hat und fehlende Stärke anscheinend nicht das Problem ist, kommen wir auf die Idee, dass Dumbo vielleicht einfach Überwindungsangst hat. Dumbo meint, dass das schon möglich ist, er ist schon lange nicht mehr geflogen und der Gedanke wieder hoch über der Erde zu fliegen, macht ihn schon etwas nervös. Um ihm zu zeigen, dass nichts Schlimmes mit fliegenden Dingen passiert, spielen wir zusammen ein Blachenvolleyball. Dazu teilen wir uns in zwei Gruppen auf, welche jeweils eine Hälfte des Volleyballfeldes zur Verfügung haben. Zwei bis vier Personen halten zusammen eine Blache, der Volleyball darf nur mit den Blachen geworfen und gefangen werden. Wenn er den Boden auf dem eigenen Feld berührt, bekommt die andere Gruppe einen Punkt.
14:35 - 14:45 Schliesslich scheint Dumbo überzeugt, dass beim Fliegen nichts Schlimmes passiert. Zum Schluss möchte er aber noch gerne ein fliegender Holländer (Hotelfangis) spielen, bei welchem er den Flugstart und die Landung noch repetieren kann. Dafür legen sich alle ausser zwei Personen über die Wiese verteilt auf den Bauch, immer zwei Personen nebeneinander. Eine der noch stehenden Personen muss die andere fangen. Die fliehende Person kann sich neben ein Pärchen legen, dann wird die Person am anderen Ende des Trios zur fangenden Person, und der die Person, die zuvor fangen musste, muss nun flüchten. Nach diesem Spiel fühlt sich Dumbo top vorbereitet, um endlich wieder zu fliegen und verabschiedet sich dankend von uns, um sein Lieblingsfluggebiet aufzusuchen.",
        "Sportart: Morgensport
Durchführungszeit: Pfingstlager, 08:00 bis 08:30
Story-Kontext: keiner (einfach nur die Sportart spielerisch behandeln)
Grober Ablauf:
08:00 - 08:10 Einstieg: Wir machen ein Böckligumpe (Bockspringen) um uns einzuwärmen.
08:10 - 08:20 Hauptteil: Variationen von Chum-mit-gang-weg (in Kreis aufstellen, einige müssen darum herum rennen).
08:20 - 08:30 Abschluss: Im Kreis herum zeigt immer jemand eine Yoga- oder Dehnübung, welche alle anderen nachmachen.

Programm:
08:00 - 08:10 Als erstes machen wir ein Böckligumpe. Die erste Person bückt sich und bildet so einen Bock. Die nächste Person muss darüberspringen und ca. 2 Meter weiter auch einen Bock bilden. Die nächste Person springt über beide anderen Personen und so weiter.
08:10 - 08:20 Wir spielen ein Chum-mit-gang-weg. Dazu stehen wir im Kreis, und jemand läuft aussen drum herum. Diese Person kann irgendjemandem auf den Rücken klopfen und entweder \"chum mit\" oder \"gang weg\" sagen. Bei \"chum mit\" müssen beide in die gleiche Richtung um den Kreis rennen, bei \"gang weg\" in entgegengesetzte Richtungen. Die Person welche zuletzt rundherum ist muss als nächstes um den Kreis laufen. Eine Variation nach einer Weile ist das Sterngame: Wir stehen nicht mehr einzeln in einem Kreis, sondern 5-6 Kollonnen von Personen stehen sternförmig in einem Kreis. Bei \"chum mit\" oder \"gang weg\" muss jeweils immer die ganze Kollonne mitrennen.
08:20 - 08:30 Wir stehen noch einmal in den Kreis. Reihum zeigt immer jemand eine Yoga- oder Dehnübung vor, und alle machen sie nach.",
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
16:15 - 16:30 Abschluss: Rangverkündigung und Klatschspiel im Kreis zum Ausklang.

Programm:
15:00 - 15:05 Ein Schotte kommt zu uns um uns in Schottland zu begrüssen und fragt uns was wir über Schottland wissen. Wir wissen schon einiges, aber noch nicht alles. Daher möchte uns der Schotte noch die Highland Games näher bringen. Dafür müssen wir alle Wanderschuhe und dem Wetter angepasste Kleidung anziehen.
15:05 - 15:15 Als Einstieg steht jemand in der Mitte, hält ein Seil in der Hand und dreht sich um sich selber. Die anderen müssen über das Seil springen. Die Personen welche ausscheiden werden auf 4 durchnummeriert, bis alle ausgeschieden sind. 
15:15 - 15:30 Die erste Disziplin ist ein Seilziehen. Es geht darum, die Mitte des Seils über die eigene Startmarkierung zu ziehen. Es treten alle Gruppen einmal gegen alle anderen Gruppen an.
15:30 - 15:45 Die zweite Disziplin ist Baumstammweitwurf. Beim Werfen muss man sich einmal um die eigene Achse drehen. Wer am weitesten werfen kann bekommt die meisten Punkte.
15:45 - 16:00 Die dritte Disziplin ist Wanderschuhwurf. Der Wanderschuh muss (ähnlich wie bei Boccia) möglichst nahe an ein Ziel (Stock) geworfen werden. Je näher am Ziel, desto mehr Punkte bekommt man.
16:00 - 16:15 Die vierte und letzte Disziplin ist Wife Carrying. Dabei muss man jemand anderes möglichst weit über einen Hindernisparcours tragen. 
16:15 - 16:30 Nun gibt es eine Rangverkündigung. Und wie es in Schottland üblich ist, wird danach musikalisch gefeiert. Wir tun dies mit einem schottischen Klatschspiel im Kreis.",
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
20:20 - 20:30 Abschluss: Fangis mit Ball

Programm:
19:30 - 19:35 Um Volleyball spielen zu können, müssen wir zunächst das Volleyballnetz aufstellen. Challenge: 1min Zeit zum Koordinieren, 4min Zeit, um zusammen das Netz aufzustellen und ein Feld zu markieren.
19:35 - 19:50 Wir bilden zwei Gruppen für Blachenvolleyball: Alle strecken mit geschlossenen Augen eine Zahl von 1-10 mit ihren Fingern in die Luft. Alle geraden Zahlen bilden eine Gruppe und die ungerade eine. Falls es nicht aufgeht, wechseln die höchsten Zahlen noch zum anderen Team. Auf jeder Seite sind nun die Spieler in Zweierteams und halten zusammen eine Blache. Die hinterste Person des Team, welches zuletzt einen Punkt gemacht hat, macht den Aufschlag. Die Teams rotieren sobald sie den Aufschlag haben. Wenn der Ball auf den Boden kommt gibts einen Punkt für das Gegnerteam. Wenn der Ball ausserhalb des Spielfelds auf den Boden kommt oder von dort aus weitergespielt wird, gibts einen Punkt für die anderen.
19:50 - 20:00 Die TN sollen ihre Schusstechnik verbessern und die Weite und Stärke ihrer Schüsse unter Kontrolle haben, sodass ein Zusammenspiel möglich wird. Manschette/Aufschlag und Zehnfinger-Technik üben: Die Leitenden erklären den TN die Technik, wie man die Hände am besten aufeinanderlegt, sodass der Ball gut darin liegt. Danach verteilen sich dieselben Zweiergruppen dem Netz entlang und passen den Ball einander zu. Ziel sind 10 Ballwechsel, ohne dass der Ball auf den Boden fällt.
20:00 - 20:20 Anschliessend machen wir ein normales Volleyball-Spiel um das Gelernte zu üben. Der Spielablauf ist gleich wie zuvor. Zusätzlich: Bei Doppelberührungen und mehr als 3 Ballberührungen gibts einen Punkt für das Gegnerteam. Das Spiel dauert 7min, dann kurze Trinkpause und dann eine Revanche.
20:20 - 20:30 Zum Abschluss spielen wir ein Fangis, bei dem die 2 Teams gegeneinander spielen. Sie müssen die anderen fangen, indem sie sie mit dem Ball berühren. Untereinander können sie sich den Ball dafür zupassen, wobei die Person mit dem Ball in der Hand nicht rennen darf.",
        "Sportart: Räuber und Bulle
Durchführungszeit: Sommerlager, 14:00 bis 15:00
Story-Kontext: Polizeitraining
Grober Ablauf:
14:00 - 14:05 Einstieg: Böckligumpe
14:05 - 14:25 Hauptteil: Teams bilden und Räuber und Bulle 
14:25 - 14:45 Hauptteil 2: Zweite Runde Räuber und Bulle mit Zusatzregeln
14:45 - 15:00 Ausklang: Hua

Programm:
14:00 - 14:05 Einstieg: Wir besammeln uns und alle sind sprtlich gekleidet. Wir laufen in den Wald und auf dem Weg machen wir ein Bockhüpfen. Das heisst wir laufen hintereinander und die vorderste Person macht ein Böckli. Dann hüpft die hintere Person darüber und macht auch ein Böckli. So geht es weiter bis alle ein Böckli sind. Dann beginnt die hinterste Person und steht auf. So alles rückwärts bis alle wieder stehen.
14:05 - 14:25 Hauptteil: Wir bestimmen die Gruppen fürs Räuber und Bulle mit \"böckli böckli wer isch das\". Die Räuber bekommen 5 Minuten, um sich im abgemachten Spielfeld zu verstecken. Die Bullen dürfen danach anfangen zu suchen. Gefangen wird durch 3maliges Klopfen auf den Rücken. Wer gefangen wird, kommt ins Gefängnis. Von dort kann man befreit werden, wenn ein freier Räuber den Gefangenen 3 Mal auf den Rücken klopft. Nach 10 Minuten Spielzeit oder beim Gewinnen der Bullen werden die Gruppen gewechselt. Wenn die Spielzeit vergeht ohne dass die Bullen alle fangen konnten, gewinnen die Räuber.
14:25 - 14:45 Hauptteil zweite Runde: Für die zweite Runde überlegen wir uns, wie wir das Spiel variieren könnten. Hier einige Vorschläge: Anzahl Räuber/Bullen verändern, Schitli-Verbannis, Zugang zum Gefängnis erschweren (Absperrband, Hindernisse, Wächter*in), Walkie-Talkies
14:45 - 15:00 Ausklang: Wir laufen in lockerem Joggen zum Lagerplatz zurück und beenden dort den Block mit einem Hua, damit die Verlierer*innen ihren Frust rauslassen können.",
    ],
];
$example = $examples[$_GET['target_group']] ?? $examples['pfadistufe'];
if (is_array($example)) {
    $example = join("\n\nWeiteres Beispiel:\n", $example);
}

$topic = $_GET['topic'];
$scaffold = $_GET['scaffold'];
$messages = [
    ['role' => 'system', 'content' => "Schreibe das Detailprogramm für einen Sportblock in einem J+S-Pfadilager für {$targetGroupDescription}, basierend auf dem gegebenen groben Ablauf.
Ein Lagersportblock (LS) muss mindestens 30 Minuten lang sein, und ist im Normalfall dreiteilig, mit Einstieg, Hauptteil, Abschluss. Bei langen Sportblöcken darf der Hauptteil auch aus mehreren Teilen bestehen.
Die Teilnehmenden müssen während dem Sportblock grösstenteils sportlich betätigt sein. Pausen sind selbstverständlich einzuplanen, wo angebracht. Es soll keine Reflexionsrunde im Sportblock vorhanden sein.
Das ausgeschriebene Detailprogramm des Sportblocks sollte in sich abgeschlossen sein, und realistisch in einem Pfadilager mit begrenztem Material und Leitpersonen durchführbar sein.

Beispiel:
{$example}

Schreibe nun das Detailprogramm für den folgenden Sportblock. Sei dabei so konkret wie möglich, beschreibe Spielregeln und Variationen im Detail.
Gib ausschiesslich den Text des Detailprogramms aus, wie im Beispiel oben. Wiederhole nicht die Metadaten (Sportart, Durchführungszeit, Story-Kontext, grober Ablauf) und lass auch das Präfix \"Programm:\" weg.
Falls die vorgegebenen Daten die Einhaltung der Vorgaben für einen Lagersportblock unmöglich machen, erkläre stattdessen, warum das so ist." ],
    ['role' => 'user', 'content' => "Sportart: {$topic}\nDurchführungszeit: {$timeframe}\nStory-Kontext: {$motto}\nGrober Ablauf:\n{$scaffold}\n\nProgramm:\n"],
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
    $sql = "INSERT INTO ls_programme (topic, target_group, motto, timeframe, scaffold, programme, cost) VALUES (?,?,?,?,?,?,?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data['topic'], $data['targetGroup'], $data['motto'], $data['timeframe'], $data['scaffold'], $data['message'], $cost]);
}
