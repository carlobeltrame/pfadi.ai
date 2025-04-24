<?php
require_once __DIR__.'/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$slugify = new Cocur\Slugify\Slugify();

$supabase = new Supabase\CreateClient($_ENV['SUPABASE_API_KEY'], $_ENV['SUPABASE_PROJECT_ID']);

$documents = [];

try {
  $documentsResponse = $supabase->from('document_names')->select()->execute();

  $documents = array_map(function($d) use ($slugify) {
    return [
      'slug' => $slugify->slugify($d['document_name']),
      'name' => $d['document_name'],
      'source' => $d['source'],
    ];
  }, $documentsResponse->data);
} catch(Error) {
  // Supabase project is currently unavailable
}

usort($documents, function ($a, $b) { return $a['name'] <=> $b['name']; });
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>pfadi.ai - AI Tools für die Pfadi</title>
  <link rel="stylesheet" href="../styles.css">
  <script src="./marked.min.js"></script>
<script>
  const documents = <?php echo json_encode($documents); ?>

  function allInputs() {
    return [
      document.querySelector('#title'),
      document.querySelector('#literature_submit'),
      document.querySelector('#literature_copy_button'),
      document.querySelector('#literature'),
      document.querySelector('#literature_history_select'),
    ].concat(documents.map(d => document.querySelector(`#documents-${d.slug}`)))
  }

  function disableAllInputs() {
    allInputs().forEach(input => input.setAttribute('disabled', ''))
    document.querySelector('#literature_results').style.display = 'none'
  }

  function enableAllInputs() {
    allInputs().forEach(input => input.removeAttribute('disabled'))
    document.querySelector('#literature_results').style.display = 'block'
  }

  function saveLiterature(data) {
    const key = 'cudesch-history-literature'
    const stored = JSON.parse(localStorage.getItem(key)) || []
    stored.push(data)
    localStorage.setItem(key, JSON.stringify(stored))
    addLiteratureHistoryEntry(data)
  }

  function loadLiterature(event) {
    const uuid = event.target.value
    const key = 'cudesch-history-literature'
    const stored = JSON.parse(localStorage.getItem(key)) || []
    const entry = stored.find(entry => entry.uuid === uuid)
    if (entry) {
      displayLiterature(entry)
      enableAllInputs()
    }
  }

  function retrieveLiterature() {
    const key = 'cudesch-history-literature'
    return JSON.parse(localStorage.getItem(key)) || []
  }

  function addLiteratureHistoryEntry(entry) {
    const historySelect = document.querySelector('#literature_history_select')
    const option = document.createElement('option')
    option.setAttribute('value', entry.uuid)
    option.appendChild(document.createTextNode(`${entry.title} ${entry.date}`))
    historySelect.appendChild(option)
  }

  function displayLiterature(data) {
    const title = document.querySelector('#title')
    const literature = document.querySelector('#literature')
    title.value = data.title
    literature.dataset.markdown = data.markdown
    literature.innerHTML = ''
    data.message.forEach(entry => displayLiteratureEntry(literature, entry))
    documents.forEach(d => {
      const checkbox = document.querySelector(`#documents-${d.slug}`)
      checkbox.checked = (data.documents || []).includes(d.name)
    })
  }

  function displayLiteratureEntry(container, entry) {
    const details = document.createElement('details')
    details.classList.add('generator-collapse')

    const summary = document.createElement('summary')
    details.appendChild(summary)

    const heading = document.createElement('span')
    heading.classList.add('generator-collapse-heading')
    heading.innerText = entry.sourceText
    summary.appendChild(heading)

    const sourceLink = document.createElement('a')
    sourceLink.href = entry.sourceUrl
    sourceLink.target = '_blank'
    sourceLink.style.marginLeft = '0.5rem'
    heading.appendChild(sourceLink)

    const sourceLinkIcon = document.createElement('img')
    sourceLinkIcon.src = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxNiAxNiI+PGcgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjMDAwIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjxwYXRoIHN0cm9rZS1saW5lam9pbj0icm91bmQiIGQ9Ik0xMiA5djVIMlY0aDUiLz48ZyBzdHJva2Utd2lkdGg9Ii42Ij48cGF0aCBzdHJva2Utd2lkdGg9Ii45NiIgZD0ibTggOCA2LTZNMTQgMmgtNE0xNCAydjQiLz48L2c+PC9nPjwvc3ZnPg=='
    sourceLinkIcon.alt = 'Quelle'
    sourceLinkIcon.width = 14
    sourceLink.appendChild(sourceLinkIcon)

    const content = document.createElement('div')
    content.classList.add('paper')
    details.appendChild(content)

    if (entry.hierarchy.length > 1) {
      const hierarchy = document.createElement('header')
      hierarchy.innerText = entry.hierarchy.join(' → ')
      content.appendChild(hierarchy)
    }

    const scrollArea = document.createElement('article')
    scrollArea.innerHTML = marked.parse(entry.markdown)
    content.appendChild(scrollArea)

    container.appendChild(details)
    animateToggle(details)
  }

  async function requestLiterature(e) {
    e.preventDefault()
    const spinner = document.querySelector('#literature_spinner')
    const literature = document.querySelector('#literature')
    const literatureHistorySelect = document.querySelector('#literature_history_select')
    const literatureForm = e.target
    // We have to retrieve the data before disabling the inputs, otherwise no data is retrieved
    const data = new URLSearchParams(new FormData(literatureForm))
    disableAllInputs()
    spinner.classList.remove('hidden')
    literature.value = ''
    literatureHistorySelect.value = '0'
    const source = new EventSource('./requestLiterature.php?' + data.toString())
    source.addEventListener('data', function (event) {
      const data = JSON.parse(event.data)
      displayLiterature(data)
      if (data.finished) {
        source.close()
        saveLiterature(data)
        literatureHistorySelect.value = data.uuid
        spinner.classList.add('hidden')
        enableAllInputs()
      }
    })
    return false
  }

  function copyLiteratureToClipboard() {
    const literature = document.querySelector('#literature')
    navigator.clipboard.writeText(literature.dataset.markdown)
  }

  function setToggleMaxHeight(collapse) {
    const childrenHeight = Array.from(collapse.children).reduce((sum, child) => {
      const styles = window.getComputedStyle(child)
      return sum + child.offsetHeight + parseFloat(styles['marginTop']) + parseFloat(styles['marginBottom'])
    }, 10)
    collapse.style.maxHeight = `${childrenHeight}px`
  }

  function animateToggle(collapse) {
    collapse.addEventListener('toggle', (event) => setToggleMaxHeight(event.target))
  }

  window.onload = () => {
    retrieveLiterature().forEach((entry) => {
      addLiteratureHistoryEntry(entry)
    })
    document.querySelectorAll(".generator-collapse").forEach(animateToggle)
    document.querySelectorAll(".generator-collapse").forEach(setToggleMaxHeight)
  }
  window.onresize = () => {
    document.querySelectorAll(".generator-collapse").forEach(setToggleMaxHeight)
  }
</script>
</head>
<body>
<header>
  <h1><a href="/">pfadi.ai - AI Tools für die Pfadi</a></h1>
</header>

<nav>
  <ul>
    <li><a href="https://pfadinamen.app">pfadinamen</a></li>
    <li><a href="/samstag">samstag</a></li>
    <li><a href="/ls">LS</a></li>
    <li><a href="/la">LA</a></li>
    <li><a href="/kursblock">kursblock</a></li>
    <li><a href="/cudesch">cudesch</a></li>
  </ul>
</nav>

<main id="main">
<div class="font-sans">
  <h2>AI-gestützte Pfadiliteratur-Suche</h2>
</div>

<?php if(count($documents) == 0) { ?>
<article class="post-it" style="max-width: 300px; min-height: 250px; margin: 30px auto;">
  <p style="font-size: 23px">Die Pfadiliteratur-Suche ist leider gerade nicht verfügbar&hellip; Versuche es später nochmals.</p>
</article>
<?php } else { ?>
<article id="block" class="generator-article">
  <form id="literature_form" onsubmit="return requestLiterature(event)">
    <div class="generator-input-group">
      <label for="title" class="generator-label">Suchbegriffe, Blocktitel, Ausbildungsinhalte</label>
      <input type="text" id="title" name="title" class="generator-input" required maxlength="128">
    </div>
    <details class="generator-collapse">
      <summary><span class="generator-collapse-heading">Durchsuchte Literatur einschränken</span></summary>
      <fieldset class="post-it">
        <div class="column-grid">
          <?php
            foreach($documents as $document) {
              $link = $document['source'] ? " <a href=\"{$document['source']}\" target=\"_blank\"><img width=\"14\" style=\"vertical-align: middle\" src=\"data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxNiAxNiI+PHBhdGggZD0iTTE0LjcgMS4zSDEzbC0uMy4xaC0xLjdjLS44IDAtMSAuNC0xIDEtLjEuNS4xLjcuNiAxbC43LjMtMSAxLTIuNCAyLjJjLS42LjUtMS40IDItMS4xIDIuMi40LjUgMS40IDAgMi0uMmwyLjctMi40IDEuNC0xLjJWNmMuMi42LjQgMSAxLjIgMSAuNSAwIDEuMS0uNiAxLjItMWwuNS00LjItLjQtLjV6bS0xMS4zIDBjLS41IDAtMS42LjYtMS43IDEuMmwtLjQgNS0xIDZjLS4xLjguNyAxLjUgMS4zIDEuNWw0LS4zIDQuMi4zaDMuMWMuNiAwIDEuMy0uOCAxLjQtMS42bC42LTRjMC0xLS4zLTEuNC0xLTEuMy0uOC4xLTEuMi42LTEuMyAxLjEgMCAwIDAgMS4yLS4zIDEuOC0uMS41LS42IDEuNC0uNiAxLjRoLTEuM2wtNC43LjItMi40LS4yIDEuMS05aDMuMmMuNi0uMyAxLjEtLjUgMS4yLTEgMC0uMy0uNC0xLjEtMS4yLTEuMS0uNSAwLS41LS4yLS44LS4yLTEuMS0uMS0zLjQuMi0zLjQuMnoiIGZpbGw9IiMzMzMiLz48L3N2Zz4=\" alt=\"Quelle\"></a>" : '';
              echo "<span><input id=\"documents-{$document['slug']}\" type=\"checkbox\" name=\"documents[]\" value=\"{$document['name']}\" />
  <label for=\"documents-{$document['slug']}\">{$document['name']}{$link}</label></span>";
            }
          ?>
        </div>
      </fieldset>
    </details>
    <div class="generator-submit-row">
      <button id="literature_submit" type="submit" class="generator-submit">Pfadiliteratur durchsuchen</button>
      <div id="literature_spinner" class="lds-dual-ring hidden"></div>
    </div>
  </form>
  <div id="literature_results" style="display: none">
    <button id="literature_copy_button" class="generator-copy-button" onclick="copyLiteratureToClipboard()" disabled="disabled">Resultate als Text kopieren 📋</button>
    <label for="literature" class="generator-label">
      Pfadiliteratur
    </label>
    <div id="literature"></div>
  </div>
</article>
<p>
  Die Literatur wurde mithilfe der Textverständnis-Fähigkeiten von ChatGPT indexiert und durchsuchbar gemacht. Falls du eine Broschüre vermisst oder zu einem Suchbegriff nichts finden kannst, kannst du dich unter cosinus ät gryfensee punkt ch melden.
</p>
<p>
  Achtung: Für die Korrektheit und Relevanz der gefundenen Inhalte wird keinerlei Garantie übernommen. Die Texte sollten in jedem Fall noch von erfahrenen Kursleitenden gegengelesen werden. Diese Suche ist ein Experiment, wie weit die aktuellen AI-Tools bereits für Pfadi-Themen nutzbar sind.
</p>
<p><small>
  Bitte keine personenbezogenen Daten in die Felder eingeben. Alle gefundenen Inhalte und dazugehörigen Eingaben werden beim Suchen zur Qualitätssicherung, Kontrolle der Kosten und Weiterentwicklung der pfadi.ai Tools serverseitig gespeichert.
</small></p>
<p>
<label for="literature_history_select">Bereits gefundene Literatur wieder aufrufen</label>
<select id="literature_history_select" class="generator-history-select" onchange="loadLiterature(event)">
  <option value="0">-</option>
</select>
</p>
<?php } ?>
</main>

<footer>
  <p>&copy; 2025 pfadi.ai - AI Tools für die Pfadi. <a href="https://github.com/carlobeltrame/pfadi.ai" target="_blank">Code auf GitHub</a></p>
</footer>
</body>