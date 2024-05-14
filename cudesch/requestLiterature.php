<?php

require_once __DIR__.'/vendor/autoload.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$supabase = new Supabase\CreateClient($_ENV['SUPABASE_API_KEY'], $_ENV['SUPABASE_PROJECT_ID']);

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

function calculateCost($embeddingResponse) {
    return $embeddingResponse->usage->totalTokens * 0.0004 / 1000;
}

function combineChapterPieces($pieces) {
    if (count($pieces) === 0) return null;
    usort($pieces, function ($a, $b) { return $a['metadata']['sequenceNumber'] <=> $b['metadata']['sequenceNumber']; });

    $resultText = '';
    $previousSequenceNumber = $pieces[0]['metadata']['sequenceNumber'] - 1;
    foreach ($pieces as $piece) {
        $resultText .= ($previousSequenceNumber < $piece['metadata']['sequenceNumber'] - 1) ? "\n\n[...]\n" : "\n";
        $resultText .= "\n" . $piece['metadata']['originalText'];
        $previousSequenceNumber = $piece['metadata']['sequenceNumber'];
    }

    $concatenatedSummaries = implode("\n", array_map(function ($piece) { return $piece['content']; }, $pieces));
    $averageSimilarity = array_sum(array_map(function ($piece) { return $piece['similarity']; }, $pieces)) / count($pieces);
    $minPageNumber = min(array_map(function ($piece) { return $piece['metadata']['pageNumber']; }, $pieces));
    $maxEndPageNumber = max(array_map(function ($piece) { return $piece['metadata']['endPageNumber']; }, $pieces));

    return array_merge(
        $pieces[0],
        [
            'id' => null,
            'content' => $concatenatedSummaries,
            'metadata' => array_merge(
                $pieces[0]['metadata'],
                [
                    'originalText' => trim($resultText),
                    'pageNumber' => $minPageNumber,
                    'endPageNumber' => $maxEndPageNumber,
                ]
            ),
            'similarity' => $averageSimilarity,
        ]
    );
}

function combineLiterature($data, $maxChapters = 3) {
    $withoutEmbeddings = array_map(function ($chapterPiece) { return array_merge($chapterPiece, ['embedding' => '(cut)' ]); }, $data);

    $groupedByChapter = [];
    foreach($withoutEmbeddings as $chapterPiece) {
        $key = $chapterPiece['metadata']['documentName'] . '--' . $chapterPiece['metadata']['chapterNumber'];
        $groupedByChapter[$key] = $groupedByChapter[$key] ?? [];
        $groupedByChapter[$key][] = $chapterPiece;
    }
    $chapters = [];
    foreach($groupedByChapter as $chapterKey => $chapterPieces) {
        $chapters[] = combineChapterPieces($chapterPieces);
    }

    usort($chapters, function ($a, $b) { return $b['similarity'] <=> $a['similarity']; });

    $chapters = array_slice($chapters, 0, $maxChapters);

    $results = array_map(function ($chapter) {
        $startPageNumber = $chapter['metadata']['pageNumber'];
        $endPageNumber = $chapter['metadata']['endPageNumber'];
        $pageNumber = $startPageNumber == $endPageNumber ? $startPageNumber : "{$startPageNumber}-{$endPageNumber}";
        $sourceUrl = $chapter['metadata']['source'];
        if (str_starts_with($sourceUrl, 'https://issuu.com')) {
            $sourceUrl .= "/{$startPageNumber}";
        } else if (str_ends_with($sourceUrl, '.pdf')) {
            $sourceUrl .= "#page={$startPageNumber}";
        }
        return [
            'documentName' => $chapter['metadata']['documentName'],
            'pages' => $pageNumber,
            'markdown' => $chapter['metadata']['originalText'],
            'hierarchy' => $chapter['metadata']['hierarchy'],
            'summary' => $chapter['content'],
            'sourceText' => $chapter['metadata']['documentName'] . ' S. ' . $pageNumber,
            'sourceUrl' => $sourceUrl,
        ];
    }, $chapters);

    $markdown = implode("\n\n", array_map(function ($chapter) {
        return "[{$chapter['documentName']} S. {$chapter['pages']}]
{$chapter['markdown']}";
    }, $results));

    return [$results, $markdown];
}

$title = $_GET['title'];
$documents = $_GET['documents'] ?? null;

$embeddingResponse = $client->embeddings()->create([
    'model' => 'text-embedding-ada-002', // use the same model as in the langchain.js uses
    'input' => $title,
]);
$queryEmbedding = $embeddingResponse->embeddings[0]->embedding;

$chaptersResponse = $supabase->rpc('match_chapters', [
    'query_embedding' => $queryEmbedding,
    'match_count' => 20,
    'document_names' => $documents,
])->execute();
[$literature, $markdown] = combineLiterature($chaptersResponse->data, 3);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$data = [
    'message' => $literature,
    'markdown' => $markdown,
    'finished' => true,
    'title' => $title,
    'documents' => $documents,
    'uuid' => uniqid(),
    'date' => date("Y-m-d H:i:s"),
];
echo renderSSE($data);

$host = $_ENV['MYSQL_HOST'];
$port = $_ENV['MYSQL_PORT'];
$dbname = $_ENV['MYSQL_DATABASE'];
$user = $_ENV['MYSQL_USER'];
$password = $_ENV['MYSQL_PASSWORD'];
if ($host && $dbname && $user && $password) {
    $cost = calculateCost($embeddingResponse);
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=UTF8";
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    $pdo = new PDO($dsn, $user, $password, $options);
    $sql = "INSERT INTO cudesch_literature (title, documents, literature, cost) VALUES (?,?,?,?)";
    $stmt= $pdo->prepare($sql);
    $stmt->execute([$data['title'], json_encode($data['documents']), json_encode($data['message']), $cost]);
}
