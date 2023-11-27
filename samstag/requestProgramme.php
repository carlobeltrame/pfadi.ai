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
    'piostufe' => 'Piostufe',
];
$targetGroup = $targetGroups[$_GET['target_group']] ?? $targetGroups['wolfsstufe'];

$targetGroupDescriptions = [
  'biberstufe' => 'Kinder zwischen 4 und 7 Jahren',
  'wolfsstufe' => 'Wölfli zwischen 7 und 11 Jahren',
  'pfadistufe' => 'Pfadis zwischen 11 und 15 Jahren',
  'piostufe' => 'Jugendliche zwischen 14 und 16 Jahren',
];
$targetGroupDescription = $targetGroupDescriptions[$_GET['target_group']] ?? $targetGroupDescriptions['gemischt'];

$activityTimes = [
  'biberstufe' => '2 Stunden an einem Samstagnachmittag',
  'wolfsstufe' => '2.5 Stunden an einem Samstagnachmittag',
  'pfadistufe' => '2.5 Stunden an einem Samstagnachmittag',
  'piostufe' => '3 Stunden an einem Samstagnachmittag',
];
$activityTime = $activityTimes[$_GET['target_group']] ?? $activityTimes['wolfsstufe'];

$examples = [
    'biberstufe' => [
        "Thema: Reh und Hase
Story: Wir treffen einen Hasen und ein Reh. Wir ahmen die Bewegungen dieser Tiere nach und bemerken, dass sie andere Fähigkeiten haben als wir Menschen.

Programm zu diesem Thema und dieser Story:
14:00-14:15 Wir machen unser kleines Besammlungs-Ritual zusammen mit unserem (Stofftier-)Biber.
14:15-14:30 Wir laufen durch den Wald und laufen einem Hasen über den Weg. Der Hase fragt sich was das für ein Biber ist. Der Biber erzählt uns von seinen Fähigkeiten und der Hase erzählt von seinen.
14:30-14:45 Wir springen mit dem Hasen ein bisschen herum. Er zeigt uns seine Skills, wir machen alles nach. 
14:45-14:50 Während wir mit dem Hasen herumhüpfen erscheint ein Reh. Das Reh ist ein Freund vom Hasen. Das Reh fragt uns was wir machen, und so will das Reh auch zeigen was es kann.
14:50-15:05 Wir spielen mit dem Hasen und Reh noch Spiele: Versteckis und Hochfangis.
15:05-15:25 Hase und Reh fragen, was denn die Menschen besonders gut können, was die Tiere nicht können. Wir suchen Holz und machen ein Feuerchen.
15:25-15:45 Auf dem Feuer bräteln wir uns Schlangenbrot als Zvieri.
15:45-16:00 Wir machen uns auf den Rückweg und machen unser kleines Abschieds-Ritual.",
        "Thema: Unter Wasser
Story: Eine Meerjungfrau hat ihren Schatz verloren. Nach einigen Tauch-Übungen können wir mit ihr unter Wasser gehen und finden dort den Schatz.

Programm zu diesem Thema und dieser Story:
14:00-14:10 Wir machen unser kleines Besammlungs-Ritual zusammen mit unserem (Stofftier-)Biber.
14:10-14:15 Eine Meerjungfrau kommt zu uns und bittet uns, ihr zu helfen: Sie hat ihren Schatz verloren und schon überall gesucht.
14:15-14:25 Doch unter Wasser zu gehen muss man üben: Wir müssen blubbern wie ein Fisch, laufen wie eine Krabbe, zu zweit mit den Armen wedeln wie ein Tintenfisch, zittern wie ein Aal, schweben wie ein Rochen, hüpfen wie ein Frosch, ...
14:25-14:35 Als letztes müssen wir noch vorbereitet sein wenn wir mal Wasser in den Mund kriegen: Staffette mit Wasser in den Mund nehmen und in einen Busch prusten.
14:35-14:50 Nun tauchen wir zusammen blubbernd unter Wasser. Die Meerjungfrau hat einen Kompass, wir fragen also immer wieder, in welche Richtung, und der Kompass zeigt es uns.
14:50-14:55 Wir finden den Schatz, aber er die Truhe ist leer! Oh nein! Doch der Pöstler kommt gleich vorbeigerannt. Er hat den Schatz mal aufgegessen, als er unterwegs hungrig war, aber jetzt wollte er gerade einen Ersatz bringen.
14:55-15:25 Wir essen den Schatz: Bananen + Schoggi
15:25-15:35 Wir spielen ein Tintenfischfangis. Jemand steht auf der Mittellinie und darf sich nur darauf seitwärts bewegen. Alle anderen müssen durchrennen ohne berührt zu werden.
15:35-15:45 Wir machen noch ein Fangis mit fixem Fänger, und wer gefangen wird wird zu einem Wassertier das wir geübt haben.
15:45-16:00 Wir machen uns auf den Rückweg und machen unser kleines Abschieds-Ritual."
    ],
  'wolfsstufe' => [
      "Thema: Jack Sparrow und der geheime Schatz
Story: Jack Sparrow sucht einen geheimen Schatz. Wir helfen ihm suchen. Unterwegs treffen wir einen Papageien, besiegen einen Gorilla und zeigen einem Piraten unsere Stärke. Am Zielort finden wir mithilfe der Karte den Schatz: Zutaten für Schoggibananen.

Programm zu diesem Thema und dieser Story:
14:00-14:15 Jack Sparrow sucht einen geheimen Schatz. Zum Glück hat er seinen Kompass, der ihn dorthin führt wo er hin will.
14:15-14:20 Doch ein Papagei kommt vorbei und klaut den Kompass. Wir rennen ihm nach.
14:20-14:40 Ein Gorilla versperrt uns den Weg. Er will Rum von uns damit er uns durchlässt. Doch Jack Sparrow hat keinen Rum mehr dabei. Dafür machen wir eine Süffelstaffette, als Ersatz für den Rum. Der Gorilla ist zufrieden und lässt uns passieren.
14:40-14:45 Am nächsten Ort wissen wir aber nicht mehr wo der Papagei hin ist. Zum Glück ist eine Piratin in der Nähe. Doch sie will uns nicht verraten wo der Papagei hin ist, ausser wir beweisen ihr, dass wir so stark sind wie sie. Zuerst versucht die Piratin alleine gegen alle Kinder ein Seilziehen, und verliert natürlich.
14:45-15:00 Doch das ist ja unfair, daher machen wir zwei Gruppen (die Piratin wählt eine Gruppe und jemand anderes die andere). Dann machen wir ein Seilziehen gegeneinander. Die Gruppe der Piratin verliert wieder, und die Piratin ist überzeugt dass wir stark sind. Die Piratin gibt uns die Richtung, in die der Papagei gegangen ist.
15:00-15:05 Dort angekommen finden wir den Papagei. Wir müssen ihn einfangen, indem wir eine Menschenkette bilden und den Papagei umrunden. Wir überzeugen ihn, uns den Kompass zu geben, wenn er dafür auch einen Teil des Schatzes bekommt.
15:05-15:15 Wir nehmen den Kompass und Jack Sparrow gibt uns den Weg an. Wir gehen bis zur Feuerstelle, wo eine Leitperson bereits ein Feuer vorbereitet hat.
15:15-15:20 Jack hat noch einen Hinweiszettel in Morseschrift, auf dem der genaue Ort des Schatzes beschrieben steht. Wir entschlüsseln die Hinweise, und wir finden den Schatz: Bananen, Schoggi und Alufolie!
15:20-16:00 Wir machen das einzig logische: Schoggibananen.
16:00-16:30 Wir machen noch New Games und gehen dann zurück.",
      "Thema: Cowboys
Story: Wir bringen einen gestohlenen Sack Gold zurück zu seinem Besitzer, einem Cowboy. Dann fangen wir noch mit Hilfe des Sheriffs die Gaunerinnen ein, die das Gold gestohlen haben.

Programm zu diesem Thema und dieser Story:
14:00-14:15 Wir machen ein paar Kennenlern-Spiele. Beim Spiel 15-14 / Fliegeralarm finden wir hinter einem Baum einen Sack voll Gold!
14:15-14:20 Im Sack ist auch ein Zettel mit einer Adresse: Lucky Jim, Saloon-Strasse, im Wald. Wir wollen das Gold natürlich dem rechtmässigen Besitzer zurückbringen, wissen aber nicht wo genau sich diese Adresse befindet. Darum gehen wir zur Postkutschen-Station.
14:20-14:45 Dort treffen wir einen Postboten. Er weiss, wo sich die Adresse befindet, hat gerade keine Karte zur Hand. Er gibt uns aber Papier und Stifte. Damit können wir in 3er-Gruppen Karten der Umgebung zeichnen. Auf jede dieser Karten zeichnet der Postbote dann so gut es geht den Ort der Adresse ein. Wir bezahlen den Postboten mit einem Goldstück, und machen uns mithilfe unserer Karten auf den Weg zur Adresse.
14:45-14:50 Dort treffen wir auf den Cowboy Lucky Jim, der es bereits aufgegeben hat, sein Gold wieder zu finden. Er ist sehr glücklich, es zurück zu haben. Allerdings hat er das Gold gar nicht verloren, sondern es wurde ihm gestohlen!
14:50-15:00 Um diesen Fall aufzuklären, gehen wir zum Sheriff um nach Gaunern in der Umgebung zu fragen. Dem Sheriff sind tatsächlich einige gefährliche Gaunerinnen bekannt, jedoch hat er diese bis jetzt noch nicht überwältigen können. Daher glaubt er auch nicht, dass wir die Gaunerinnen einfangen könnten.
15:00-15:15 Wir demonstrieren dem Sheriff unser Können mit einem Trojanisches-Pferd-Spiel. Der Sheriff ist von unseren Fähigkeiten beeindruckt.
15:15-15:35 Der Sheriff führt uns zum Ort wo die Gaunerinnen oft aufzufinden sind. Unterwegs stossen wir aber auf eine Kontrolle der Gangster. Um uns da durchzuschleichen, sitzt immer ein Kind auf die Schultern einer Leitperson, und legt sich einen grossen Mantel über die Schultern. Falls die Kontrolleure Fragen stellen, muss das Kind den Mund bewegen und die Leitperson mit tiefer Stimme reden.
15:35-15:55 Die Gaunerinnen streiten sich gerade darüber, wo der Sack mit Gold sein könnte, da er nicht mehr hinter dem Baum ist, wo sie ihn versteckt haben. Wir umkreisen sie und können sie überwältigen und mit herumliegenden Seilen an den Händen fesseln. Dabei können wir gleich einige nützliche Pfadi-Knoten ausprobieren. Der Sheriff bedankt sich bei uns und bringt die gefesselten Gaunerinnen ins Gefängnis.
15:55-16:30 Als Dank für unsere Aufrichtigkeit und Hilfe teilt Lucky Jim sein Gold mit uns. Wir essen von den Goldtalern und noch weiteren Zvieri am Lagerfeuer der Gaunerinnen, und gehen dann zufrieden zurück zum Pfadiheim.",
  ],
  'pfadistufe' => [
      "Thema: Alchemisten
Story: Die Alchemisten haben unsere Fahne geklaut, und wir müssen sie zurückholen. Mit der Hilfe eines abtrünnigen Alchemisten finden und sabotieren wir das Ritual der Alchemisten, welche unsere Fahne zu Gold verarbeiten wollen.

Programm zu diesem Thema und dieser Story:
14:00-14:15 Wir stellen fest, dass unsere Fahne verschwunden ist. Wir finden aber einen Zettel mit einem Rezept für die Herstellung von Gold. Dieses stammt von einer Gruppe von Alchemisten. Auf dem Rezept sind die meisten Zutaten abgehakt, aber es steht etwas von „Gegenstand mit historischer Wichtigkeit“. Die Alchemisten haben also die Fahne geklaut weil sie sie zur Herstellung von Gold brauchen. Es steht auch ein schwammig beschriebener Ort, wo der Dieb die Fahne weitergeben soll, und etwas von \"Signalfeuer\".
14:15-14:40 Wir gehen also in die beschriebene Gegend. In der Nähe geht ein Vulkan los. Doch als wir dorthin kommen ist niemand da, wir sind zu spät. Aus dem Gebüsch tönt ein Walkie-Talkie. Einer der Alchemisten kann sich nicht mehr mit seiner Gruppe identifizieren und will uns helfen. Er erklärt dass die Alchemisten als nächstes ein Ritual durchführen wollen, und dass die Fahne dabei verbrannt werden soll. Der Alchemist hat ein Schweigegelübde abgelegt, daher kann er uns nicht sagen, wo das Ritual stattfinden wird. Wir müssen das Ritual sabotieren, indem wir 3 Zauber zusammensuchen. Dazu müssen wir 3 verschiedene Magier*innen besuchen. Diese wollen jedoch jeweils eine Gegenleistung. Der Alchemist lotst uns jeweils zu den Orten.
14:40-14:55 Magierin 1: Hörzauber. Wir spielen das Übermittlungs-Spiel. Dabei muss man jemandem eine Nachricht über eine störend schreiende Gruppe hinweg mitteilen.
14:55-15:15 Magier 2: Desorientierungszauber. Wir machen eine Mohrenkopf-Ess-Staffette: Man muss einzeln nach vorne rennen, mit Händen hinter dem Rücken auf den Bauch liegen und einen Mohrenkopf essen, dann zurückrennen.
15:15-15:35 Magierin 3: Sehzauber. Nach den Sternen greifen (wie Reiter-auf-Reiter-ab): Wir stehen in einem Kreis immer paarweise hintereinander. Die äusseren Personen rennen auf Kommando rundherum und hechten zwischen Beinen des inneren Kreises in die Mitte, um einen dort liegenden Leuchtstern zu ergreifen.
15:35-15:50 Der Alchemist sagt nun, den Ort des Rituals findet man wenn man auf der Karte eine Linie durch zwei der Magier-Orte zieht, und an dieser Linie den dritten Magier-Ort spiegelt.
15:50-16:00 Endlich finden wir das Ritual. Die Pfadis müssen für die Störzauber einen grossen Kreis um das Ritual bilden. Es hat ein farbiges Feuer mit einem Wasserkessel darauf. Wir müssen die 3 Zaubersprüche gemeinsam aufsagen. Als wir fertig sind knallt es, die Alchemisten spucken Blut und fallen bewusstlos um. Wir können unsere Fahne zurückholen.
16:00-16:30 Wir essen zur Feier des Tages noch einen Zvieri und gehen dann zurück.",
      "Thema: Hammerschmied
Story: Wir helfen einem Schmied, dessen Hammer geklaut wurde. Unterwegs finden wir heraus, dass der Hammer eingeschmolzen wurde, und stellen einen neuen Hammer her. Schliesslich stellt sich heraus dass der Schmied ein Betrüger war, und wir geben den Hammer dem echten Schmied.

Programm zu diesem Thema und dieser Story:
14:00 - 14:15 Der Schmied ist beim Arbeiten eingeschlafen und als er wieder aufgewacht ist waren seine Hände eingeschmiedet (in Alufolie eingewickelt) und sein Hammer verschwunden. Wir helfen ihm natürlich erst Mal, das zu entfernen, indem wir ein Seil um seine Hände wickeln und ein Seilziehen machen.
14:15 - 14:25 Zum Dank schenkt uns der Schmied einen Feuerstab (Metallstück mit dem man viele Funken machen kann). Er hat aber immer noch keinen Hammer. Um einen neuen Hammer zu schmieden müssen wir zum Zinnverkäufer neues Metall holen gehen. Der Schmied gibt uns eine Karte auf der der Weg eingezeichnet ist.
14:25 - 14:35 Unterwegs werden wir von einem Zinnja (Zinn-Ninja) überfallen, und wir machen ein Hua gegen ihn, damit er uns passieren lässt.
14:35 - 14:50 Der Zinnverkäufer glaubt uns nicht, dass wir Schmiede sind, und möchte zuerst in einem Baumstammweitwurf überprüfen, wie stark wir sind. Wir beweisen unser Können.
14:50 - 15:10 Doch der Verkäufer glaubt uns immer noch nicht, denn es hat viele Betrüger in diesem Wald. Er verlangt von uns, dass wir aus Holz ein Modell vom Hammer schnitzen, welchen wir schmieden möchten. Alle nehmen also ihr Sackmesser hervor und schnitzen einen Hammer.
15:10 - 15:15 Als es ans Bezahlen geht bekommt der Zinnverkäufer einen Anruf, und sagt etwas über einen Hammer den er gerade eingeschmolzen hat.
15:15 - 15:55 Wies aussieht hat der Zinnverkäufer den Hammer des Schmieds geklaut und eingeschmolzen. Wir rennen also schnell mit dem Zinn weg. Zurück beim Schmied müssen wir den Hammer wieder neu zusammenschmieden. Eine Hälfte der Gruppe baut eine Gussform indem sie die Form in den Boden eingraben und mit Alufolie auskleiden. Die andere Hälfte macht mit dem Feuerstab ein Feuer, da das alte Feuer vom Schmied ausgegangen ist. Jemand kann zudem einen schönen Holzstab als Griff suchen.
15:55 - 16:05 Nun schmelzen wir den Zinn in einem kleinen Kessel auf dem Feuer, giessen ihn in die Gussform und stecken den Holzgriff hinein bevor es erkaltet.
16:05 - 16:10 Der Schmied bedankt sich bei uns, und wir machen uns auf den Rückweg. Unterwegs finden wir einen Mann der an einen Baum gefesselt ist. Wir befreien ihn, und es stellt sich heraus dass das der richtige Schmied ist, der andere war nur ein Betrüger.
16:10 - 16:15 Als Rache gehen wir nochmals zum falschen Schmied, und lenken ihn mit einem Rap Battle ab, während zwei Pfadis den geschmiedeten Hammer klauen.
16:15 - 16:30 Der echte Schmied bedankt sich bei uns, denn er kann jetzt ein neues Hammer Business aufbauen."
  ],
];
$example = $examples[$_GET['target_group']] ?? $examples['wolfsstufe'];
if (is_array($example)) {
    if (count($example) == 1) $example = $example[0];
    $example = join("\n\nWeiteres Beispiel:\n", $example);
}

$title = $_GET['title'];
$story = $_GET['story'];
$messages = [
    ['role' => 'system', 'content' => "Schreibe Pfadiprogramm für {$targetGroupDescription}. Vorgegeben sind das Thema sowie die grobe Story.
Das Programm sollte in sich abgeschlossen sein und in ca. {$activityTime} durchführbar sein.

Beispiel:
{$example}
" ],
    ['role' => 'user', 'content' => "Thema: {$title}\nStory:\n{$story}\n\nProgramm zu diesem Thema und dieser Story:\n"],
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
    'ageGroup' => $_GET['target_group'],
    'targetGroup' => $targetGroup,
    'story' => $story,
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
    $sql = "INSERT INTO samstag_programme (title, target_group, story, programme, cost) VALUES (?,?,?,?,?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data['title'], $data['targetGroup'], $data['story'], $data['message'], $cost]);
}
