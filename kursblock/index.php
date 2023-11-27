<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>pfadi.ai - AI Tools für die Pfadi</title>
  <link rel="stylesheet" href="../styles.css">
<script>
  function allInputs() {
    return [
      document.querySelector('#title'),
      document.querySelector('#age_group'),
      document.querySelector('#target_group'),
      document.querySelector('#goals_submit'),
      document.querySelector('#goals'),
      document.querySelector('#programme_submit'),
      document.querySelector('#programme'),
      document.querySelector('#goals_history_select'),
      document.querySelector('#programme_history_select'),
    ]
  }

  function disableAllInputs() {
    allInputs().forEach(input => input.setAttribute('disabled', ''))
  }

  function enableAllInputs() {
    allInputs().forEach(input => input.removeAttribute('disabled'))
  }

  function saveGoals(data) {
    const key = 'kursblock-history-goals'
    const stored = JSON.parse(localStorage.getItem(key)) || []
    stored.push(data)
    localStorage.setItem(key, JSON.stringify(stored))
    addGoalsHistoryEntry(data)
  }

  function loadGoals(event) {
    const uuid = event.target.value
    const key = 'kursblock-history-goals'
    const stored = JSON.parse(localStorage.getItem(key)) || []
    const entry = stored.find(entry => entry.uuid === uuid)
    if (entry) {
      displayGoals(entry)
    }
  }

  function retrieveGoals() {
    const key = 'kursblock-history-goals'
    return JSON.parse(localStorage.getItem(key)) || []
  }

  function addGoalsHistoryEntry(entry) {
    const historySelect = document.querySelector('#goals_history_select')
    const option = document.createElement('option')
    option.setAttribute('value', entry.uuid)
    option.appendChild(document.createTextNode(`${entry.title} (${entry.courseType}) ${entry.date}`))
    historySelect.appendChild(option)
  }

  function displayGoals(data) {
    const title = document.querySelector('#title')
    const ageGroup = document.querySelector('#age_group')
    const targetGroup = document.querySelector('#target_group')
    const goals = document.querySelector('#goals')
    const programme = document.querySelector('#programme')
    const programmeHistorySelect = document.querySelector('#programme_history_select')
    title.value = data.title
    ageGroup.value = data.ageGroup
    targetGroup.value = data.targetGroup.toLowerCase()
    goals.value = data.message
    programme.value = ''
    programmeHistorySelect.value = '0'
  }

  async function requestGoals(e) {
    e.preventDefault()
    const spinner = document.querySelector('#goals_spinner')
    const goals = document.querySelector('#goals')
    const programme = document.querySelector('#programme')
    const goalsHistorySelect = document.querySelector('#goals_history_select')
    const goalsForm = e.target
    // We have to retrieve the data before disabling the inputs, otherwise no data is retrieved
    const data = new URLSearchParams(new FormData(goalsForm))
    disableAllInputs()
    spinner.classList.remove('hidden')
    goals.value = ''
    programme.value = ''
    goalsHistorySelect.value = '0'
    const source = new EventSource('./requestGoals.php?' + data.toString())
    source.addEventListener('data', function (event) {
      const data = JSON.parse(event.data)
      displayGoals(data)
      if (data.finished) {
        source.close()
        saveGoals(data)
        goalsHistorySelect.value = data.uuid
        spinner.classList.add('hidden')
        enableAllInputs()
      }
    })
    return false
  }

  function saveProgramme(data) {
    const key = 'kursblock-history-programme'
    const stored = JSON.parse(localStorage.getItem(key)) || []
    stored.push(data)
    localStorage.setItem(key, JSON.stringify(stored))
    addProgrammeHistoryEntry(data)
  }

  function loadProgramme(event) {
    const uuid = event.target.value
    const key = 'kursblock-history-programme'
    const stored = JSON.parse(localStorage.getItem(key)) || []
    const entry = stored.find(entry => entry.uuid === uuid)
    if (entry) {
      displayProgramme(entry)
    }
  }

  function retrieveProgramme() {
    const key = 'kursblock-history-programme'
    return JSON.parse(localStorage.getItem(key)) || []
  }

  function addProgrammeHistoryEntry(entry) {
    const historySelect = document.querySelector('#programme_history_select')
    const option = document.createElement('option')
    option.setAttribute('value', entry.uuid)
    option.appendChild(document.createTextNode(`${entry.title} (${entry.courseType}) ${entry.date}`))
    historySelect.appendChild(option)
  }

  function displayProgramme(data) {
    const title = document.querySelector('#title')
    const ageGroup = document.querySelector('#age_group')
    const targetGroup = document.querySelector('#target_group')
    const goals = document.querySelector('#goals')
    const programme = document.querySelector('#programme')
    const goalsHistorySelect = document.querySelector('#goals_history_select')
    title.value = data.title
    ageGroup.value = data.ageGroup
    targetGroup.value = data.targetGroup.toLowerCase()
    goals.value = data.goals
    programme.value = data.message
    goalsHistorySelect.value = '0'
  }

  async function requestProgramme(e) {
    e.preventDefault()
    const spinner = document.querySelector('#programme_spinner')
    const programme = document.querySelector('#programme')
    const programmeHistorySelect = document.querySelector('#programme_history_select')
    const goalsForm = document.querySelector('#goals_form')
    const programmeForm = e.target
    if ((!goalsForm.reportValidity()) || (!programmeForm.reportValidity())) {
      return false
    }
    // We have to retrieve the data before disabling the inputs, otherwise no data is retrieved
    const data = new URLSearchParams(mergeFormData(goalsForm, programmeForm))
    disableAllInputs()
    spinner.classList.remove('hidden')
    programme.value = ''
    programmeHistorySelect.value = '0'
    const source = new EventSource('./requestProgramme.php?' + data.toString())
    source.addEventListener('data', function (event) {
      const data = JSON.parse(event.data)
      displayProgramme(data)
      if (data.finished) {
        source.close()
        saveProgramme(data)
        programmeHistorySelect.value = data.uuid
        spinner.classList.add('hidden')
        enableAllInputs()
      }
    })
    return false
  }

  function mergeFormData(...forms) {
    const formData = new FormData()
    forms.forEach(form => {
      for(entry of (new FormData(form)).entries()) {
        formData.append(entry[0], entry[1])
      }
    })
    return formData
  }

  window.onload = () => {
    retrieveGoals().forEach((entry) => {
      addGoalsHistoryEntry(entry)
    })
    retrieveProgramme().forEach((entry) => {
      addProgrammeHistoryEntry(entry)
    })
  }
</script>
</head>
<body>
<header>
  <a href="https://pfadi.ai"><h1>pfadi.ai - AI Tools für die Pfadi</h1></a>
</header>

<nav>
  <ul>
    <li><a href="https://pfadinamen.app">Pfadinamen</a></li>
    <li><a href="/samstag">Pfadiprogramm</a></li>
    <li><a href="/kursblock">Kursblöcke</a></li>
  </ul>
</nav>

<section id="main">
<div class="font-sans">
  <h2>Kursblock-AI</h2>
</div>

<article id="block" class="generator-article">
  <form id="goals_form" onsubmit="return requestGoals(event)">
    <div class="generator-input-group">
      <label for="title" class="generator-label">Blocktitel</label>
      <input type="text" id="title" name="title" class="generator-input" required maxlength="128">
    </div>
    <div class="generator-input-group">
      <label for="age_group" class="generator-label">Alter der Kurs-TN</label>
      <select id="age_group" class="generator-input" name="age_group" required>
        <option value="14-15_pio">14 - 15 Jahre (Piokurs)</option>
        <option value="15-16_futura">15 - 16 Jahre (Futurakurs)</option>
        <option value="16-17_basis" selected="selected">16 - 17 Jahre (Basiskurs)</option>
        <option value="17-18_aufbau">17 - 18 Jahre (Aufbaukurs)</option>
        <option value="17-18_ef">17 - 18 Jahre (Stufeneinführungskurs)</option>
        <option value="19-24_panorama">19 - 24 Jahre (Panoramakurs)</option>
        <option value="21-27_top">21 - 27 Jahre (Topkurs)</option>
      </select>
    </div>
    <div class="generator-input-group">
      <label for="target_group" class="generator-label">Zielgruppe der Kurs-Anerkennung (falls relevant)</label>
      <select id="target_group" class="generator-input" name="target_group" required>
        <option value="biberstufe">Biberstufe</option>
        <option value="wolfsstufe">Wolfsstufe</option>
        <option value="pfadistufe" selected="selected">Pfadistufe</option>
        <option value="gemischt" selected="selected">gemischt Wolfsstufe - Pfadistufe</option>
        <option value="piostufe">Piostufe</option>
      </select>
    </div>
    <div class="generator-submit-row">
      <button id="goals_submit" type="submit" class="generator-submit">Blockziele generieren und 5 Rappen von Cosinus verbrauchen</button>
      <div id="goals_spinner" class="lds-dual-ring hidden"></div>
    </div>
  </form>
  <form id="programme_form" onsubmit="return requestProgramme(event)">
    <label for="goals" class="generator-label">Blockziele</label>
    <textarea id="goals" name="goals" class="generator-input" rows="10" required="required"></textarea>
    <div class="generator-submit-row">
      <button  id="programme_submit" type="submit" class="generator-submit">Blockprogramm generieren und 25 Rappen von Cosinus verbrauchen</button>
      <div id="programme_spinner" class="lds-dual-ring hidden"></div>
    </div>
  </form>
  <label for="programme" class="generator-label">Blockprogramm</label>
  <textarea id="programme" name="programme" class="generator-input" rows="20"></textarea>
</article>
<p>
  Die Blockziele und das Blockprogramm werden mit ChatGPT generiert. Das Generieren kann bis zu 60 Sekunden dauern, und kostet den Autor dieser Webseite (Cosinus) echtes Geld. Wenn du den Generator viel benützt, kannst du etwas dafür twinten: null sibä nüün, drüü acht sächs, sächs sibä, null sächs.
</p>
<p>
  Achtung: Für die Qualität oder Korrektheit der generierten Inhalte wird keinerlei Garantie übernommen. Die Texte sollten in jedem Fall noch von erfahrenen Kursleiter*innen gegengelesen werden. Dieser Generator ist ein Experiment, wie weit die aktuellen AI-Tools bereits für Pfadi-Themen nutzbar sind.
</p>
<p><small>
  Bitte keine personenbezogenen Daten in die Felder eingeben. Alle generierten Inhalte und dazugehörigen Eingaben werden beim Generieren zur Qualitätssicherung und Kontrolle der Kosten serverseitig gespeichert.
</small></p>
<p>
<label for="goals_history_select">Bereits generierte Blockziele wieder aufrufen</label>
<select id="goals_history_select" class="generator-history-select" onchange="loadGoals(event)">
  <option value="0">-</option>
</select>
</p>
<p>
<label for="programme_history_select">Bereits generiertes Blockprogramm wieder aufrufen</label>
<select id="programme_history_select" class="generator-history-select" onchange="loadProgramme(event)">
  <option value="0">-</option>
</select>
</p>
</section>

<footer>
  <p>&copy; 2023 pfadi.ai - AI Tools für die Pfadi</p>
</footer>
</body>