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
16:40 - 16:50 Morsen üben: Wir üben das Morsealphabet mit einem Spiel noch besser auswendig. Der Kapitän zieht erfreut davon.
16:50 - 17:00 Abschluss: Der Kapitän kann nun das nötige Wissen seiner Crew kommunizieren.

Programm:
16:00 - 16:05 Ein Schiffskapitän kommt zu uns. Sein Schiff ist hier gestrandet, da die Seile, mit denen er sein Schiff für eine Pause über Nacht, befestigt hat, gerissen sind und er nun mit seiner Mannschaft abgetrieben ist. Er fragt uns nach Rat und wie er das hätte vermeiden können, da er nicht mehr weiter weiss. Wir fragen uns z.B. welche Seile er verwendet hat da wir alle ein bisschen Seil-Wissen im Hinterkopf haben. Nun geht es ans Auffrischen und Repetieren - Seilkunde, damit der Kapitän nicht nochmal so einen Fehler macht.
16:05 - 16:20 **Seilkunde - Seiltypen**
Punkte mit Kapitän und TN sammeln, ergänzen, zusammenfassen. Wir haben Beispiele für die verschiedenen Seiltypen dabei.
Hanfseile:
- Gedreht aus Naturfasern
- Geringere Lebensdauer und Reissfestigkeit, aber billiger
- Wird bei Nässe kürzer und dicker => Knöpfe sind schwer zu öffnen
- Kann verrotten, immer trocken versorgen
- Für Lager- und Pionierbauten
Bergseile:
- Geflochten aus Nylon mit Kern
- Nur zum Abseilen und Sichern => sehr dehnbar
- Nicht dauerbelasten, da es sonst beschädigt wird
- Achtung bei (Reibungs-)Hitze => tiefer Schmelzpunkt
(Halb-)Statikseile:
- Geflochten aus Nylon mit Kern
- NICHT dehnbar
- Für Seilbahnen und -brücken
- Achtung bei (Reibungs-)Hitze => tiefer Schmelzpunkt
Polypropylenseile:
- Gedreht aus Kunstfasern => kein Kern
- Oft dünnere, kürzere Seile
- Sehr tiefer Schmelzpunkt und tiefere Belastbarkeit
- Ungeeignet für Sicherungen oder Lagerbauten => Wäsche trocknen :)
—> Zum Merken spielen wir ein Spiel. Seilfakten/Merkmale werden vorgelesen und die TN müssen zuordnen, zu welchem Seiltyp sie gehören. (im Stil von 1, 2 oder 3 an den richtigen Ort stehen)
16:20 - 16:30 Es geht weiter mit dem Thema **Seilpflege** (Punkte mit den TN sammeln)
Seilpflege:
- Immer trocken versorgen
- Vor allem Berg und Statikseile: nicht im Bund versorgen
- Nicht auf Seile stehen
- Seile nicht über scharfe Kanten führen
- Vor jedem Gebrauch: Seile von Auge und von Hand prüfen => Seilkern kann gebrochen sein, ohne dass der Mantel etwas davon zeigt!
Um dieses Wissen zu festigen, machen wir ein Pantomime. Ein TN muss den anderen eine Regel anhand eines positiven/negativen Beispiel pantomimisch vorzeigen und die anderen müssen erraten, welche Regel gemeint ist.
16:30 - 16:40 Nun, der Kapitän weiss jetzt mehr über Seile. Er will das Wissen so schnell wie möglich seiner Mannschaft unten am See mitteilen, da sie die Seile im Moment noch falsch lagern. Wieder zum See zu wandern würde sehr lange dauern und wir suchen nach Kommunikationsmöglichkeiten über Distanz. Wir kommen bald auf **Morsen**.
Die Leitperson erklärt kurz den Grundsatz von Morseschrift und wie das Morsealphabet aufgebaut ist. Die Gruppe geht unter Anleitung durch das Morsealphabet durch und wiederholt kurz die Merkwörter zu den Buchstaben.
Wir suchen nun nach Übermittlungsmöglichkeiten von Morsen: Schreiben, klopfen, Licht, Stimme, Pfeife, etc.
16:40 - 16:50 Nun spielen  wir ein Spiel: Wir machen Pärchen und stehen dann in einen Kreis.
Die eine Person jedes Pärchens muss der anderen Person gegenüber ein bestimmtes (zugeteiltes) Wort zumorsen mit einer dieser Techniken. Das Pärchen, das sich als erstes ein Wort kommunizieren konnte, gewinnt. Je nach dem wie gut es funktioniert, spielen wir noch eine Runde.
16:50 - 17:00 Wir haben erfolgreich Morsen und Seilkunde repetiert, und der Kapitän dankt uns für das Wissen. Er kann nun losziehen und seiner Crew das Wissen kommunizieren.
",
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
14:40 - 14:45 Ausstieg: Hängematten ausprobieren, die Moderatorin bedankt sich.

Programm:
14:00 - 14:05 Eintieg: Die Fernsehmoderatorin sagt, die Wohnwagen für die Fernsehcrew seien nicht geliefert worden. Wo sollen die Leute nur schlafen? Wir schlagen ihr vor, Blachenzelte zu bauen, da wir Blachen dabeihaben. Doch sie entgegnet, dass die Crew heikel ist und nicht auf dem Boden schlafen will, ausserdem haben wir keine Zelteinheiten. Also bauen wir Hängematten.
14:05 - 14:20 Ausprobieren: Es hängt eine vorgebaute Hängematte zwischen 2 Bäumen. In 3er Gruppen schauen die TN sich die Vorlage an und versuchen sie nachzubauen. Sobald alle ein, zwei Seile zwischen den Bäumen gespannt haben, wird unterbrochen.
14:20 - 14:30 Musterbeispiel zeigen: Wir zeigen die einzelnen Bestandteile der Hängematte vor: Zwei Seile mit Maurer oder Mastwurf am Baum befestigen. Fuhrmannknoten spannen und abbretzeln. 
14:30 - 14:40 Verbessern: Mit Hilfe der Leitpersonen verbessern die TN ihre Hängematte oder stellen sie fertig, indem sie 2 Äste, ev. mit Kerbe oder Astgabeln, zwischen die Seile spannen und 2 Blachen darum legen und sie zusammenknüpfen.
14:40 - 14:45 Ausstieg: Alle dürfen in ihre Hängematte liegen. Die Fernsehmoderatorin bedankt sich bei uns, nachdem auch sie sich in eine Hängematte hineingelegt hat und geht die Fernsehcrew abholen.",
        "Lerninhalte: Blachen, Seil, Pflanzenkunde
Durchführungszeit: Pfingstlager, 10:00 bis 11:45
Story-Kontext: Apollon vs. Athena
Grober Ablauf:
10:00 - 10:05 Einstieg: Apollon und Athena wollen herausfinden wer sich besser mit Pfaditechnik auskennt und veranstalten einen Wettbewerb.
10:05 - 10:30 Gruppenaufteilung: Anhand kleiner Challenges (Hölzchen mit Schnur rechtwinklig verbinden) auf zwei durchnummerieren.
10:15 - 10:30 Material suchen: Mit Orientierungs-Challenges das nötige Material für den Wettbewerb an verstreuten Orten zusammensuchen.
10:30 - 11:00 Wissen erwerben: Innerhalb der Halbgruppen beschäftigt sich je ein Drittel mit Blachenzelten, mit Knoten und mit ansässigen Pflanzen + Feuermachen.
11:00 - 11:30 Präsentieren: Das Erlernte und Erreichte wird präsentiert, wo nötig kommentiert und für den Wettbewerb bewertet.
11:30 - 11:45 Abschluss-Challenge: Bei Unfallsituation wo Zeus verletzt wurde abwechslungsweise Handlungsanweisungen geben für Bonuspunkte im Wettbewerb.

Programm:
10:00 - 10:05 Einstieg: Apollon und Athena streiten sich, wer sich besser mit Pfaditechnik auskennt. Um die Sache zu klären, veranstalten sie einen Wettbewerb. Der Kurs wird auf die beiden Gottheiten aufgeteilt.
10:05 - 10:30 Gruppenaufteilung: Dazu bekommen alle TN zwei Zundhölzer und ein Stück Schnur. Die Aufgabe ist es jetzt, die Zundhölzer mit der Schnur rechtwinklig zu verbinden. Alle die fertig sind stehen in eine Schlange (und die TN die es nicht schaffen dürfen bei denen in der Schlange nach Hilfe fragen). In der so entstandenen Reihe nummerieren wir auf zwei durch. So werden Leute mit guten und weniger guten Pfaditechnikkenntnissen durchmischt. Die eine Hälfte der TN geht zu Apollon und die andere Hälfte zu Athena.
10:15 - 10:30 Material suchen: Innerhalb der Kurshälften dürfen sich die TN nun noch frei in drei Untergruppen aufteilen. Eine Untergruppe bekommt ein Kroki mit einem markierten Ort, die zweite einen Kompass und eine Azimut- und Distanzangabe und die dritte bekommt eine Karte und Koordinaten. Die drei Untergruppen beider Götter müssen nun diese Orte aufsuchen und das dort liegende Material zurück zum Haus bringen (bzw. nur die Hälfte, sodass die jeweilige Gruppe des anderen Gottes auch noch etwas bekommt).
10:30 - 11:00 Wissen erwerben: Wenn alle wieder beim Haus versammelt sind, verkünden Apollon und Athena, wie es im Wettbewerb weitergeht. Die Gruppen haben eine halbe Stunde Zeit für ihre Aufgaben, danach kommen alle wieder zusammen.
BLACHEN-MEISTER*INNEN: Je eine Untergruppe der beiden Gottheiten hat 13 Blachen, 6 Zelteinheiten und eine Pfaditechnik gefunden (die Gruppe die zuerst da war konnte gute Blachen nehmen, die andere hat Ausschussblachen). Diese beiden Gruppen müssen ein möglichst grosses Zelt bauen, welches alle vorhandenen Blachen verwendet. Dafür haben sie die vollen 30 Minuten zu Verfügung.
SEIL-SPEZIALIST*INNEN: Je eine Untergruppe der beiden Gottheiten hat Seile und eine Liste mit Knoten erhalten. Diese Knoten sind in der Pfaditechnik mit Bildern und Erklärungen beschrieben. Das Ziel dieser Gruppen ist es, möglichst viele dieser Knoten auszuprobieren und sich zu merken. Nach Ablauf der Zeit müssen sie die Knotenbeschreibungen wieder abgeben.
NATUR-EXPERT*INNEN: Je eine Untergruppe der beiden Gottheiten hat ein verschlossenes Couvert gefunden, in dem sich Bilder von einigen üblichen Pflanzen und deren Name und Merkmale befinden. Das Ziel dieser beiden Gruppen ist es, möglichst viele der beschriebenen Pflanzen in der Umgebung zu finden und ein Blatt oder ein Foto von der Pflanze mitbringen. Gleichzeitig haben beide Untergruppen eine vorgegebene Feuerstelle, über welche eine Schnur gespannt ist. Die Untergruppen müssen ein Feuer machen das so schnell wie möglich die Schnur durchbrennt.
11:00 - 11:30 Präsentieren: Wir kommen wieder zusammen und bewerten im Plenum die Leistung der Gruppen in den drei Disziplinen.
BLACHEN-MEISTER*INNEN: Die beiden Gruppen zeigen ihr Zelt allen TN und erklären, wie sie es erweitert haben. Die Gruppe kann von 0 bis 5 Punkte bekommen für: Alle Blachen verbaut, Wetterfestigkeit, Stabilität, Platz im Zelt, Kreativität
SEIL-SPEZIALIST*INNEN: Im Plenum wechseln sich die Knotenspezialist*innen der beiden Gottheiten ab und erklären und zeigen jeweils einen gelernten Knoten (der noch nicht gezeigt wurde). Die Gruppe die ihr Material zuerst gefunden hat darf beginnen. Jede Gruppe kann maximal 5 Punkte (1 pro gezeigten Knoten) gewinnen.
NATUR-EXPERT*INNEN: Jede Gruppe die es im Zeitlimit geschafft hat, ihre Schnur durchzubrennen, bekommt 1 Punkt. Im Plenum wechseln sich die Natur-Spezialist*innen wie die Seil-Expert*innen ab und dürfen jeweils eine gefundene Pflanze (die noch nicht genannt wurde) zeigen und ihre Erkennungsmerkmale erklären. Die Gruppe die zuerst das Material gefunden hat darf beginnen. Jede Gruppe kann maximal 4 Punkte (1 pro gezeigte Pflanze) gewinnen.
11:30 - 11:45 Abschluss-Challenge: Plötzlich hören wir ein lautes rumpeln und Schreie. Wir eilen zur Geräuschquelle und finden dort Zeus in einer Unfallsituation auf. Immer abwechslungsweise sollen die beiden Teams eine Aktion nennen, die in der dargestellten Situation als nächstes gemacht werden soll. Für jede korrekte Aktion gibt es einen Punkt. Die siegreiche Gruppe gewinnt die ewige Gunst ihrer Gottheit für den Rest des Lagers.",
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
17:55 - 18:15 Ausstieg: Zwei bisher eher ruhige TN leiten die Gruppe an, ein Lagerfeuer zu machen. Austausch am Feuer

Programm:
17:00 - 17:10 Einstieg: Wir starten mit einer kleinen Standortbestimmung: Die TN stellen sich in eine Reihe, ca. eine Armlänge voneinander entfernt und schliessen die Augen. Es werden Aussagen getätigt, den sie zustimmen können oder nicht, indem sie einen grossen Schritt vor machen (ja) oder stehen bleiben (nein). Auch die Leitenden schliessen die Augen, damit die Umfrage anonym stattfinden kann. Während der Umfrage sind alle still und überlegen sich, ob es eine Aussage gibt, zu der sie eine persönliche Geschichte später teilen möchten. Am Schluss öffnen alle die Augen und können vergleichen, wo sie stehen. Wir gehen die Fragen noch einmal durch und alle können eine persönliche Erfahrung teilen.
1) Ich habe schon einmal Rassismus in der Pfadi miterlebt
2) Ich wurde schon einmal von einer Leitperson gedemütigt, ausgegrenzt oder beleidigt
3) Ich habe schon einmal eine Schlägerei in der Pfadi erlebt, die kein Spass mehr war
4) Ich habe schon einmal jemanden in der Pfadi ausgeschlossen
5) Mir ist jemand in der Pfadi schon einmal körperlich zu nahe gekommen, sodass es für mich unangenehm war
6) Ich fühle mich von meinen Leitenden nicht wertgeschätzt
7) Mobbing ist es erst nach 6 Monaten
8) Ich habe schon einmal ein Ritual in der Pfadi erlebt, das ich zu gewalttätig/brutal/demütigend fand
9) Ich gebe nicht allen in der Pfadi das Gefühl, sie seien willkommen
10) Ich bin schon einmal in der Pfadi ausgeschlossen worden
11) Ich habe schon einmal in der Pfadi (absichtlich oder unabsichtlich) Sachbeschädigung begangen
12) Ich fand diese Umfrage unnötig
17:10 - 17:20 Thema fassen: Frage in die Runde: Zu welchem Thema waren diese Fragen? -> Alle dürfen auf Zettel aufschreiben, um welche Themen sich diese Fragen drehen. Mögliche Antworten: Mobbing, Gewalt, Pfadi, Rassismus, Gruppengefühl, Gruppendynamik, Ausgrenzung, Beziehung Leitende-Pfadis/Pios, Rituale und Traditionen.
Zusammen versuchen wir, die Themen auf den Zetteln zu gruppieren. Dabei ergibt sich folgendes Schema:
- physische Gewalt (Schlägereien, Schlägereien aus Spass, brutale Rituale, Fairplay)
- psychische Gewalt (Ausgrenzung, Rolle in der Gruppe, Mobbing durch andere TN oder Leitende) 
- verbale Gewalt
- Rassismus
- Sachbeschädigung
- sexuelle Gewalt
17:20 - 17:30 Mobbing: Wir gehen noch etwas genauer auf \"Mobbing und Ausgrenzung\" ein. Welche Arten von Mobbing gibt es?
- verbales Mobbing: Gerüchte verbreiten, gemeine Spitznamen verpassen, lästern
- nonverbales Mobbing: ausgrenzen, ignorieren, fiese Mimik und Gestik anwenden
- körperliches Mobbing: schlagen, bedrohen, Sachen wegnehmen, sexuelle Belästigung, jemanden zu etwas zwingen
- Cybermobbing: Filmen, Abbildungen, Beleidigungen ins Internet stellen
- rassistisches/sexistisches Mobbing: fremdenfeindliche Äusserungen, Bedrohung wegen der Nationalität, Hautfarbe, Religion, Behinderung 
-> all das sind auch wieder Formen der Gewalt
Laut \"offizieller\" Definition, gilt eine Gewaltanwendung als Mobbing, wenn Menschen…
1. eine Person, von einer oder einigen Personen 
2. systematisch,
3. oft (mindestens einmal pro Woche) und
4. über längere Zeit
5. direkt oder indirekt 
… angegriffen werden 
17:30 - 17:40 Was ist Gewalt: Bereits in der vorherigen Diskussion haben wir bemerkt, dass die Meinungen, darüber, was schon Gewalt ist und was nicht, auseinander gehen.  Die Leitenden teilen reale Erlebnisse, die sie oder andere als Grenzüberschreitung (und somit als Gewalt) wahrgenommen haben und die TN positionieren sich auf 2 Seiten (Ja/Nein), je nachdem, ob sie diese Erlebnisse ebenfalls als Gewalt einschätzen oder nicht.
1) Wölfli: Pumba haut Fiela auf den Arsch, um sie zu ärgern
2) Pios: Pumba haut Fiela auf den Arsch, um sie zu ärgern
3) Pumba ist leicht übergewichtig und ziemlich schlau. Seine Kollegen sagen darum immer \"lieber ein paar Kilos zu viel, als ein paar Gehirnzellen zu wenig\"
4) Fiela und Sky wollen nicht mit Pumba in der Gruppe sein, weil er in der Schule schon immer nervt
5) Fiela und Sky wollen mit Pumba nicht in der Gruppe sein und sie erzählen allen anderen, dass er total nervig ist
6) Pumba erzählt oft von seiner Grossmutter. Die anderen Pios nennen ihn immer \"Muetersöhnli\"
7) Pumba hängt oft mit Mädchen rum. Die anderen Jungs sagen, er sei ein \"Meitlischmöcker\", meinen es aber nicht ernst
8) Massagekreis, bei dem man die Füsse der Person neben sich massieren muss   
Die TN können jetzt noch eigene Beispiele bringen.  
17:40 - 17:50 Miteinander reden:
1. Massagekreis (! Achtung Freiwilligkeit)
Die TN setzen sich in einen Kreis und massieren die Schultern der rechts von ihr sitzenden Person. Dabei darf NICHT gesprochen werden. Die Leitenden machen dasselbe untereinander. Nach 3min hören wir auf.
REFLEXION: 
- Wie hat sich das angefühlt? 
- Wärst du lieber nicht massiert worden? Warum hast du dich (nicht) für deine Bedürfnisse eingesetzt? 
- Wie hättest du das ohne Worte tun können? 
- Wärst du lieber an einer anderen Stelle massiert worden oder von einer anderen Person?
Falls von den TN keine Antworten kommen, schildern die Leitenden ihre Erfahrung. 
2. Fütterung
Wir bilden 2er-Teams und legen ihnen eine Auswahl an Sugus in verschiedenen Geschmacksrichtungen vor. Eine Person soll nun für die andere ein Sugus auswählen, je nachdem, was sie denkt, gefällt der anderen Person am besten.
Die zu andere Person überlegt sich im Vorhinein, welches Sugus sie am liebsten hätte, darf sich aber weder verbal noch mit einer übertriebenen Reaktion dazu äussern.
REFLEXION:
- Wurde der richtige Geschmack des Gegenübers getroffen?
- Wenn ja: Warum? Zufall oder gute Kenntnis der anderen Person?
SCHLUSSFOLGERUNGEN: 
Wenn sich die anderen nicht äussern – verbal oder nonverbal – ist es schwierig, zu wissen, was ihre Bedürfnisse sind.
17:50 - 17:55 Teambildungsspiel: Das perfekte Quadrat/Dreieck
Zur Gruppenbildung: versuchen, Gruppen zu durchmischen und diejenigen, die etwas lauter sind/ oft den Lead übernehmen bestmöglich zu trennen
Je 4 oder 3 TN bekommen ein Seil in die Hand. Sie sollen nun versuchen mit verbundenen Augen ein perfektes Quadrat (bzw. gleichseitiges Dreieck) mit diesem Seil zu formen. Sie können sich dabei absprechen, sehen aber nichts.
REFLEXION:
- Wer hat den Lead übernommen?
- Habt ihr euch bewusst darauf geeinigt oder ist das einfach passiert? 
17:55 - 18:15 Ausstieg: Die Gruppe bestimmt gemeinsam 2 Personen, die sich in der Vergangenheit selten eingebracht/durchgesetzt haben. Diese leiten nun den Rest der Gruppe dabei an, eine Feuerstelle zu errichten und ein Feuer zu entzünden. Sie dürfen dabei selber nichts machen, sondern nur Befehle geben.  Ziel soll sein, dass die \"stilleren\" Personen üben, sich durchzusetzen und die \"lauteren\" sich unterzuordnen.
Wir sitzen noch am Feuer und die TN haben Gelegenheit, zu sagen, was sie brauchen, um sich in der Gruppe ganz wohl zu fühlen.",
    ],
];
$example = $examples[$_GET['target_group']] ?? $examples['pfadistufe'];
if (is_array($example)) {
    $example = join("\n\nWeiteres Beispiel:\n", $example);
}

$topic = $_GET['topic'];
$scaffold = $_GET['scaffold'];
$messages = [
    ['role' => 'system', 'content' => "Schreibe das Detailprogramm für eine Lageraktivität in einem J+S-Pfadilager für {$targetGroupDescription}, basierend auf dem gegebenen groben Ablauf.
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

Das ausgeschriebene Detailprogramm der Lageraktivität sollte in sich abgeschlossen sein, und realistisch in einem Pfadilager mit begrenztem Material und Leitpersonen durchführbar sein.

Beispiel:
{$example}

Schreibe nun das Detailprogramm für die folgende Lageraktivität. Sei dabei so konkret wie möglich, beschreibe Spielregeln und allfällige Rätsel, und liste konkrete inhaltliche Punkte auf, mit denen man sich in der Aktivität beschäftigt.
Gib ausschiesslich den Text des Detailprogramms aus, wie im Beispiel oben. Wiederhole die Metadaten (Lerninhalte, Durchführungszeit, Story-Kontext, grober Ablauf) nicht und lass auch das Präfix \"Programm:\" weg.
Falls die vorgegebenen Daten die Einhaltung der 5 Regeln für eine Lageraktivität unmöglich machen, erkläre stattdessen, warum das so ist." ],
    ['role' => 'user', 'content' => "Lerninhalte: {$topic}\nDurchführungszeit: {$timeframe}\nStory-Kontext: {$motto}\nGrober Ablauf:\n{$scaffold}\n\nProgramm:\n"],
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
    $sql = "INSERT INTO la_programme (topic, target_group, timeframe, scaffold, programme, cost) VALUES (?,?,?,?,?,?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data['topic'], $data['targetGroup'], $data['timeframe'], $data['scaffold'], $data['message'], $cost]);
}
