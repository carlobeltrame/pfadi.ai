import 'dotenv/config'
import { OpenAIEmbeddings } from 'langchain/embeddings/openai'
import { SwissGermanLLM } from './swissGermanLLM.js'
import { LLMChainExtractor } from 'langchain/retrievers/document_compressors/chain_extract'
import { RunnableSequence } from 'langchain/schema/runnable'
import { PromptTemplate } from 'langchain/prompts'
import { CudeschPDFLoader } from './cudeschPdfLoader.js'
import { SupabaseVectorStore } from 'langchain/vectorstores/supabase'
import { createClient } from '@supabase/supabase-js'
import { TextSplitOutputParser } from './textSplitOutputParser.js'

const WOLFSSTUFE = 'Die Wolfsstufe - Mis Bescht'
const PFADISTUFE = 'Die Pfadistufe - Allzeit bereit'
const PFADIPROFIL = 'Pfadiprofil'
const PFADI = 'Pfadi - das sind wir'
const PROGRAMM = 'Programm - Pfadi leben'
const SICHERHEIT = 'Sicherheit - Verantwortung tragen'
const RQF = 'Rückmelden, Qualifizieren und Fördern im Ausbildungskurs'
const PFADITECHNIK = 'Pfaditechnik in Wort und Bild'

const supabaseApiKey = process.env.SUPABASE_API_KEY
if (!supabaseApiKey) throw new Error(`Expected env var SUPABASE_API_KEY`)
const supabaseUrl = process.env.SUPABASE_URL
if (!supabaseUrl) throw new Error(`Expected env var SUPABASE_URL`)
const supabaseClient = createClient(supabaseUrl, supabaseApiKey)
const supabaseClientParams = () => ({
  client: supabaseClient,
  tableName: 'chapters',
  queryName: 'match_chapters',
})
const llm = new SwissGermanLLM({
  maxTokens: -1,
  modelName: process.env.OPENAI_MODEL_NAME || 'gpt-4-1106-preview',
})
const embeddings = new OpenAIEmbeddings()

const pbsTextFilter = (left = 70, right = 455, top = 800, bottom = 50, ignoreFonts = [], oddLeft = left, oddRight = right) => (item) => {
  // Exclude text items in the left and right side margins, these are only suggested further reading
  return item.transform[4] > (item.odd ? oddLeft : left) && item.transform[4] < (item.odd ? oddRight : right) &&
    // Exclude the text at the top and bottom of each page, this is just the name of the document or page numbers
    item.transform[5] < top && item.transform[5] > bottom &&
    // Exclude certain fonts, these may be used in graphics
    !ignoreFonts.includes(item.fontName)
}
const pbsTextTransformer = (h1Size = 24, h2Size = 14, h3Size = 11, headingRegexp = /^\d/) => (item) => {
  const cleanedStr = item.str.replaceAll(/\s+(?=\s)/g, '')
  if (!item.str.match(headingRegexp)) return { ...item, str: cleanedStr }
  const prefix = item.transform[0] >= h3Size ? (item.transform[0] >= h2Size ? (item.transform[0] >= h1Size ? '# ' : '## ') : '### ') : ''
  return { ...item, str: prefix + cleanedStr }
}
const pbsEducationTextTransformer = (h1Regexp = /^\d /, h2Regexp = /^\d\.\d /, h3Regexp = /^\d\.\d\.\d /) => (item) => {
  const cleanedStr = item.str.replaceAll(/\s+(?=\s)/g, '').replaceAll('', '')
  if (item.str.match(h3Regexp)) return { ...item, str: '### ' + cleanedStr }
  if (item.str.match(h2Regexp)) return { ...item, str: '## ' + cleanedStr }
  if (item.str.match(h1Regexp)) return { ...item, str: '# ' + cleanedStr }
  return { ...item, str: cleanedStr }
}
const matchesRule = (item, rule) => {
  if ('regexp' in rule && !item.str.match(rule.regexp)) return false
  if ('fontSize' in rule && item.transform[0] !== rule.fontSize) return false
  if ('fontName' in rule && item.fontName !== rule.fontName) return false
  if ('leftLessThan' in rule && item.transform[4] >= rule.leftLessThan) return false
  return true
}
const flexibleTransformer = (h1Rule, h2Rule, h3Rule) => (item) => {
  const cleanedStr = item.str.replaceAll(/\s+(?=\s)/g, '')
  if (matchesRule(item, h1Rule)) return { ...item, str: '# ' + cleanedStr }
  if (matchesRule(item, h2Rule)) return { ...item, str: '## ' + cleanedStr }
  if (matchesRule(item, h3Rule)) return { ...item, str: '### ' + cleanedStr }
  return { ...item, str: cleanedStr }
}

const loaders = [
  new CudeschPDFLoader('documents/pfadistufe.pdf', {
    source: 'https://issuu.com/pbs-msds-mss/docs/2134.01.de_cudesch_pfadistufenbrosc',
    documentName: PFADISTUFE,
    textItemFilter: pbsTextFilter(70, 455, 800, 0),
    textItemTransformer: pbsTextTransformer(24, 14),
    skip: 5,
    skipEnd: 6,
    enabled: true,
  }),
  new CudeschPDFLoader('documents/wolfsstufe.pdf', {
    source: 'https://issuu.com/pbs-msds-mss/docs/2118.02.de_die_wolfsstufe_mis_besch',
    documentName: WOLFSSTUFE,
    textItemFilter: (item) => {
      if (['g_d0_f4', 'g_d0_f3'].includes(item.fontName) && item.transform[0] < 12 && item.str.trim()) return false
      return pbsTextFilter(42, 550, 800, 35)(item)
    },
    textItemTransformer: (item) => {
      const cleanedStr = item.str.replaceAll(/\s+(?=\s)/g, '')
      if (['g_d0_f4', 'g_d0_f3'].includes(item.fontName) && item.transform[0] >= 12) return { ...item, str: cleanedStr }
      return pbsTextTransformer(24, 14)(item)
    },
    skip: 3,
    skipEnd: 2,
    enabled: true,
  }),
  new CudeschPDFLoader('documents/pfadiprofil.pdf', {
    source: 'https://issuu.com/pbs-msds-mss/docs/2120.01.de-pfadiprofil-p___dagogisc',
    documentName: PFADIPROFIL,
    textItemFilter: pbsTextFilter(0, 1000, 800, 50, ['g_d0_f4']),
    textItemTransformer: pbsTextTransformer(24, 14, 13, /./),
    skip: 4,
    skipEnd: 4,
    enabled: true,
  }),
  new CudeschPDFLoader('documents/programm.pdf', {
    source: 'https://issuu.com/pbs-msds-mss/docs/rz_05_programm_de_201607_issuu',
    documentName: PROGRAMM,
    textItemFilter: pbsTextFilter(70, 455, 800, 50),
    textItemTransformer: pbsTextTransformer(),
    skip: 2,
    skipEnd: 2,
    enabled: true,
  }),
  new CudeschPDFLoader('documents/pfadi.pdf', {
    source: 'https://pfadi.swiss/media/files/9f/01_pfadi_de_web.pdf',
    documentName: PFADI,
    textItemFilter: pbsTextFilter(70, 455, 800, 50),
    textItemTransformer: pbsTextTransformer(24, 14, 11, /./),
    skip: 2,
    skipEnd: 3,
    enabled: true,
  }),
  new CudeschPDFLoader('documents/sicherheit.pdf', {
    source: 'https://pfadi.swiss/media/files/a6/08_sicherheit_de_web.pdf',
    documentName: SICHERHEIT,
    textItemFilter: pbsTextFilter(45, 475, 800, 50),
    textItemTransformer: pbsTextTransformer(24, 14, 11, /./),
    skip: 3,
    skipEnd: 1,
    enabled: false,
  }),
  new CudeschPDFLoader('documents/rqf.pdf', {
    source: 'https://issuu.com/pbs-msds-mss/docs/3118.01de-rqf-20160831-akom',
    documentName: RQF,
    textItemFilter: (item) => {
      if ([26, 27].includes(item.page)) return false
      return pbsTextFilter(150, 475, 800, 50, [], 0, 470)(item)
    },
    textItemTransformer: pbsEducationTextTransformer(),
    skip: 6,
    skipEnd: 5,
    enabled: true,
  }),
  new CudeschPDFLoader('documents/pfaditechnik.pdf', {
    source: 'https://www.hajk.ch/de/pfaditechnik-in-wort-und-bild-pfadi-glockenhof',
    documentName: PFADITECHNIK,
    textItemFilter: (item) => {
      if ([83, 112, 144, 145, 146, 147, 148, 149, 169, 170, 171].includes(item.page)) return false
      if (item.transform[0] <= 8) return false
      return pbsTextFilter(50, 475, 800, 45)(item)
    },
    textItemTransformer: flexibleTransformer(
      { fontSize: 16 },
      { regexp: /^\d\.\b/, leftLessThan: 60 },
      { regexp: /^\d\.\d\.\b/, leftLessThan: 60 }
    ),
    skip: 10,
    skipEnd: 7,
    enabled: true,
  }),
]

function findSentenceStartAround (content, splitPoint, range = 400) {
  for (let i = 0; i <= range; i++) {
    const forward = splitPoint + i
    if (forward < content.length && content.substring(0, forward).match(/\.\s$/)) {
      return forward
    }
    const backward = splitPoint - i
    if (backward >= 0 && content.substring(0, backward).match(/\.\s$/)) {
      return backward
    }
  }
  return splitPoint
}

function splitBetweenSentences (content, maxChapterLength = 5000) {
  if (content.length > maxChapterLength && maxChapterLength > 0) {
    const middle = findSentenceStartAround(content, Math.ceil(content.length / 2), Math.ceil(maxChapterLength / 4))
    return [
      splitBetweenSentences(content.slice(0, middle)),
      splitBetweenSentences(content.slice(middle))
    ].flat()
  }
  return [content]
}

/* Split large chapters into smaller pieces, to make sure an LLM step is not overwhelmed with a huge
text and introduces mistakes (and to make sure the context window isn't exceeded).
maxChapterLength can be set to 0 to disable splitting. */
async function splittingLargeChaptersBetweenSentences (docs, handler, maxChapterLength = 5000) {
  return await Promise.all(docs.map(async doc => {
    const chapterParts = splitBetweenSentences(doc.pageContent, maxChapterLength)
      .map(content => ({ ...doc, pageContent: content }))

    const result = await handler(chapterParts)

    return { ...doc, pageContent: result.map(chapterPart => chapterPart.pageContent).join('\n') }
  }))
}

async function cleanChapters (chapters) {
  const promptTemplate = new PromptTemplate({
    template: `Putze den folgenden rohen Text heraus. Korrigiere überschüssigen Whitespace, entferne Zeilenumbrüche in der Mitte von Sätzen, entferne Trennstriche von den Zeilenenden, und formatiere den Text als Markdown. Gib den Text inhaltlich komplett unverändert aus. Gib nur den Markdown-Inhalt aus, ohne \`\`\`markdown etc. rundherum.

Roher Text:
{context}

Herausgeputzter Markdown-Text:`,
    inputVariables: ['context']
  })
  const cleaner = LLMChainExtractor.fromLLM(llm, promptTemplate)

  return await cleaner.compressDocuments(chapters, '')
}

async function splitSemantically (content, pageBreaks, averageChapterSize = 1000) {
  const promptTemplate = new PromptTemplate({
    template: `Unterteile den gegebenen Text in ${Math.floor(content.length / averageChapterSize)} sinnvolle Kapitel. Jedes Kapitel darf unterschiedlich lang sein und mehrere Abschnitte enthalten, sollte aber mindestens 3 Sätze lang sein, für sich stehend verständlich sein (der Kontext soll klar sein) und die semantische Bedeutung beibehalten. Zwischentitel sind gute Unterteilungspunkte. Lass nichts aus, jeder Teil des Textes muss zwingend in genau einem der Kapitel enthalten sein. Gib ausschliesslich eine JSON-formatierte Liste von Unterteilungspunkten aus. Angenommen du bekommst folgenden Beispieltext:

## Verkehrsministerium beschliesst Warnwestenpflicht für Rehe und Hirsche
Auf Initiative von Verkehrsminister Volker Wissing (FDP) sollen ab kommendem Jahr sämtliche Hirscharten - darunter Rehe sowie Rot- und Damwild - zum Tragen einer Warnweste verpflichtet werden. Mit seinem Vorschlag will der Minister die Zahl der Wildunfälle drastisch senken. Unterstützt wird er dabei vom Deutschen Jagdverband, der sich schon seit Langem besser gekennzeichnetes Wild wünscht. "Leider sind die meisten Hirscharten äusserst unzuverlässig", erklärt der Forstbeamte Andreas Range, der immer wieder angefahrenen Rehen den Gnadenschuss geben muss. "Seit Jahren hängen wir kostenlose Reflektoren an den Rändern von Waldstrassen auf – meinen Sie, auch nur ein Reh hätte sich einmal einen davon umgehängt, um nachts besser gesehen zu werden? Fehlanzeige!"

Dann gib Unterteilungspunkte wie folgt formatiert aus:

[{{"chapterStart": "## Verkehrsministerium beschliesst Warnwestenpflicht für", "relationToFullText": "Warnwestenpflicht für Tiere um Wildunfälle zu senken"}}, {{"chapterStart": "\\"Leider sind die meisten Hirscharten äusserst unzuverlässig\\", erklärt", "relationToFullText": "Warnwestenpflicht für Tiere weil bisher Reflektoren von Tieren ignoriert werden"}}]

Achte darauf, den Text in "chapterStart" eindeutig zu wählen und in keiner Weise zu verändern, damit eine Maschine die Textstelle im Originaltext automatisiert finden kann. "relationToFullText" soll für sich allein genommen den Kontext / Überblick geben, worum es im Kapitel geht und welchen Teil des Gesamttextes das Kapitel abdeckt.

Zu unterteilender Text:
{content}`,
    inputVariables: ['content']
  })
  const parser = new TextSplitOutputParser(content, pageBreaks)
  const chain = RunnableSequence.from([
    promptTemplate,
    llm,
    parser,
  ])
  // Try up to 3 times before failing permanently
  return await chain
    .withFallbacks({ fallbacks: [chain, chain] })
    .invoke({ content })
}

async function summarize (content) {
  const promptTemplate = new PromptTemplate({
    template: `Fasse in wenigen Stichworten (maximal 1 Satz!) worum es im gegebenen Text geht. Kein vollständiger Satz, nur Stichworte!
Hier 2 Beispiel-Zusammenfassungen von anderen Texten:
Warnwestenpflicht für Tiere weil bisher Reflektoren von Tieren ignoriert werden
Definition von Qualität bei Pfadiaktivitäten und Wege wie wir die Pfadis persönlich weiterbringen können

Gegebener Text:
{content}`,
    inputVariables: ['content']
  })
  const chain = RunnableSequence.from([ promptTemplate, llm ])
  return await chain.invoke({ content })
}

async function addMetadataAndSplitLargeChapters (chapters, pageBreaks) {
  const AVERAGE_CHAPTER_SIZE = 1500
  return (await Promise.all(chapters.map(async chapter => {
    if (chapter.pageContent.length > 2 * AVERAGE_CHAPTER_SIZE) {
      const pageBreaksInChapter = pageBreaks.filter((pageBreak) => {
        return pageBreak.page >= chapter.metadata.pageNumber && pageBreak.page <= chapter.metadata.endPageNumber
      })
      const splits = await splitSemantically(chapter.pageContent, pageBreaksInChapter, AVERAGE_CHAPTER_SIZE)
      return splits.map((split, index) => ({
        ...chapter,
        pageContent: split.summary,
        metadata: {
          ...chapter.metadata,
          sequenceNumber: index,
          pageNumber: split.pageNumber,
          endPageNumber: split.endPageNumber,
          originalText: split.content,
        }
      }))
    } else {
      const summary = await summarize(chapter.pageContent)
      return [{
        ...chapter,
        pageContent: summary,
        metadata: {
          ...chapter.metadata,
          originalText: chapter.pageContent,
        },
      }]
    }
  }))).flat()
}

async function indexTheory (loaders) {
  const vectorStore = await SupabaseVectorStore.fromExistingIndex(embeddings, supabaseClientParams())

  for (const loader of loaders) {
    // Load the chapters from the PDF
    let chapters = await loader.load()
    if (!chapters.length) continue;

    //chapters.sort((chapter1, chapter2) => chapter2.pageContent.length - chapter1.pageContent.length)
    //chapters.sort((chapter1, chapter2) => chapter1.pageContent.length - chapter2.pageContent.length)
    //chapters = [chapters[0], chapters[chapters.length - 1]]
    //chapters = chapters.slice(0, 1)
    //console.log(chapters.map(c => c.pageContent).join('\n\n')); return

    console.log('cleaning', chapters.length, 'chapters')
    // Clean up all formatting artifacts from the PDF parsing process, resulting in nice Markdown text
    chapters = await splittingLargeChaptersBetweenSentences(chapters, async (chapters) => {
      return await cleanChapters(chapters)
    })

    //console.log(chapters); return

    console.log('summarizing', chapters.length, 'chapters')
    // Add keyword summaries of all chapters, and semantically split long chapters into smaller ones
    chapters = await addMetadataAndSplitLargeChapters(chapters, loader.pageBreaks)
    console.log('got', chapters.length, 'chapters ready for saving')

    if (!chapters.length) continue

    // Remove any previous stored embeddings of the same PDF
    await supabaseClient.rpc('delete_chapters_by_document', { document_name: loader.documentName })

    // Create embeddings and store them into supabase
    await vectorStore.addDocuments(chapters)
  }
  console.log('saved!')
}

await indexTheory(loaders)
