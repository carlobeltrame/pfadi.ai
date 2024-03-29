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
  modelName: process.env.OPENAI_MODEL_NAME || 'gpt-4-0125-preview',
})
const embeddings = new OpenAIEmbeddings()

const matchesRule = (item, previousItem, rule) => {
  if (rule.never) return false
  if (rule.regexp && !item.str.match(rule.regexp)) return false
  if (rule.fontName && ![rule.fontName].flat().includes(item.fontName)) return false
  if (rule.fontNameNot && [rule.fontNameNot].flat().includes(item.fontName)) return false
  if (rule.xLessThan && item.transform[4] >= rule.xLessThan) return false
  if (rule.xGreaterThan && item.transform[4] <= rule.xGreaterThan) return false
  if (rule.xGreaterThanOnOddPages && item.odd && item.transform[4] <= rule.xGreaterThanOnOddPages) return false
  if (rule.xGreaterThanOnEvenPages && !item.odd && item.transform[4] <= rule.xGreaterThanOnEvenPages) return false
  if (rule.xLessThanOnOddPages && item.odd && item.transform[4] >= rule.xLessThanOnOddPages) return false
  if (rule.xLessThanOnEvenPages && !item.odd && item.transform[4] >= rule.xLessThanOnEvenPages) return false
  if (rule.yLessThan && item.transform[5] >= rule.yLessThan) return false
  if (rule.yGreaterThan && item.transform[5] <= rule.yGreaterThan) return false
  if (rule.yEq && item.transform[5] !== rule.yEq) return false
  if (rule.fontSize && item.transform[0] !== rule.fontSize) return false
  if (rule.fontSizeGreaterThan && item.transform[0] <= rule.fontSizeGreaterThan) return false
  if (rule.fontSizeGreaterOrEq && item.transform[0] < rule.fontSizeGreaterOrEq) return false
  if (rule.fontSizeLessThan && item.transform[0] >= rule.fontSizeLessThan) return false
  if (rule.page && ![rule.page].flat().includes(item.page)) return false
  if (rule.pageNot && [rule.pageNot].flat().includes(item.page)) return false
  if (rule.pageGreaterOrEq && item.page < rule.pageGreaterOrEq) return false
  if (rule.pageLessOrEq && item.page > rule.pageLessOrEq) return false
  if (rule.color && JSON.stringify(rule.color) !== JSON.stringify(item.color)) return false
  if (rule.colorNot && JSON.stringify(rule.colorNot) === JSON.stringify(item.color)) return false
  if (rule.startOfLine &&
    item.page === previousItem.page &&
    item.transform[5] === previousItem.transform[5] &&
    item.transform[4] >= previousItem.transform[4] &&
    previousItem.str
  ) return false
  if (rule.anyOf && rule.anyOf.every(r => !matchesRule(item, previousItem, r))) return false
  return true
}
const canBeJoinedHeading = (item, previousItem, level, rule) => {
  // Joining is not disabled explicitly
  return rule.joining !== false &&
    // The previous item is already a heading of this type
    previousItem.heading === level && (
      // Either the item is on the same line as the previous item...
      matchesRule(item, previousItem, { yEq: previousItem.transform[5], page: previousItem.page }) ||
      // ...or in the case of multiline headings, the current item must match the same rules as the previous item,
      // except for some start-of-line related rules
      (rule.multiline !== false &&
        matchesRule(item, previousItem, { ...rule, regexp: undefined, startOfLine: undefined, page: previousItem.page }))
    )
}

const onlyTextWhere = (rule = {}) => (item, previousItem) => {
  return matchesRule(item, previousItem, rule)
}
function transformText({ h1 = { never: true }, h2 = { never: true }, h3 = { never: true }, bold = { never: true }, emphasis = { never: true } }) {
  return (item, previousItem) => {
    const cleanedStr = item.str.replaceAll(/\s+(?=\s)/g, '')

    if (matchesRule(item, previousItem, h1)) return { ...item, str: cleanedStr, heading: 1 }
    if (matchesRule(item, previousItem, h2)) return { ...item, str: cleanedStr, heading: 2 }
    if (matchesRule(item, previousItem, h3)) return { ...item, str: cleanedStr, heading: 3 }

    if (canBeJoinedHeading(item, previousItem, 1, h1)) {
      return { ...item, str: cleanedStr, heading: 1, joinWithPrevious: true }
    }
    if (canBeJoinedHeading(item, previousItem, 2, h2)) {
      return { ...item, str: cleanedStr, heading: 2, joinWithPrevious: true }
    }
    if (canBeJoinedHeading(item, previousItem, 3, h3)) {
      return { ...item, str: cleanedStr, heading: 3, joinWithPrevious: true }
    }

    return {
      ...item,
      str: cleanedStr,
      bold: matchesRule(item, previousItem, bold),
      emphasis: matchesRule(item, previousItem, emphasis)
    }
  }
}

const loaders = [
  new CudeschPDFLoader('documents/pfadistufe.pdf', {
    source: 'https://issuu.com/pbs-msds-mss/docs/2134.01.de_cudesch_pfadistufenbrosc',
    documentName: PFADISTUFE,
    textItemFilter: onlyTextWhere({
      xGreaterThanOnOddPages: 70, xLessThanOnOddPages: 460,
      xGreaterThanOnEvenPages: 132, xLessThanOnEvenPages: 525,
      yLessThan: 800
    }),
    textItemTransformer: transformText({
      h1: { fontSizeGreaterOrEq: 24, regexp: /^\d+\b/ },
      h2: { fontSizeGreaterOrEq: 14, regexp: /^\d+\.\d+\b/ },
      h3: { fontSizeGreaterOrEq: 11, regexp: /^\d+\.\d+\.\d+\b/ },
      bold: { fontName: ['g_d0_f2', 'g_d0_f7', 'g_d0_f9'] },
    }),
    skip: 5,
    skipEnd: 6,
    enabled: true,
  }),
  new CudeschPDFLoader('documents/wolfsstufe.pdf', {
    source: 'https://issuu.com/pbs-msds-mss/docs/2118.02.de_die_wolfsstufe_mis_besch',
    documentName: WOLFSSTUFE,
    textItemFilter: onlyTextWhere({
      xGreaterThanOnOddPages: 70, xLessThanOnOddPages: 555,
      xGreaterThanOnEvenPages: 42, xLessThanOnEvenPages: 525,
      yGreaterThan: 35, yLessThan: 800,
      anyOf: [{ fontNameNot: ['g_d0_f4', 'g_d0_f3'] }, { fontSizeGreaterOrEq: 12 }],
    }),
    textItemTransformer: transformText({
      h1: { fontSize: 24, regexp: /^\d+\b/, fontNameNot: ['g_d0_f4', 'g_d0_f3'] },
      h2: { fontSize: 14, regexp: /^\d+\.\d+\b/, fontNameNot: ['g_d0_f4', 'g_d0_f3'] },
      h3: { fontSize: 11, regexp: /^\d+\.\d+\.\d+\b/ },
      bold: { fontName: ['g_d0_f2'] },
    }),
    skip: 3,
    skipEnd: 2,
    enabled: true,
  }),
  new CudeschPDFLoader('documents/pfadiprofil.pdf', {
    source: 'https://issuu.com/pbs-msds-mss/docs/2120.01.de-pfadiprofil-p___dagogisc',
    documentName: PFADIPROFIL,
    textItemFilter: onlyTextWhere({ yGreaterThan: 50, yLessThan: 800, fontNameNot: ['g_d0_f4'] }),
    textItemTransformer: transformText({
      h1: { fontSizeGreaterOrEq: 16, regexp: /^\d+\.(\D|$)/, fontName: 'g_d0_f2' },
      h2: { fontSizeGreaterOrEq: 16, regexp: /^\d+\.\d+/, fontName: 'g_d0_f2' },
      h3: { fontSizeGreaterOrEq: 14, fontName: 'g_d0_f2' },
      bold: { fontName: ['g_d0_f2'] },
      emphasis: { fontName: ['g_d0_f3'] },
    }),
    skip: 4,
    skipEnd: 4,
    enabled: true,
  }),
  new CudeschPDFLoader('documents/programm.pdf', {
    source: 'https://issuu.com/pbs-msds-mss/docs/rz_05_programm_de_201607_issuu',
    documentName: PROGRAMM,
    textItemFilter: onlyTextWhere({
      xGreaterThanOnOddPages: 70, xLessThanOnOddPages: 455,
      xGreaterThanOnEvenPages: 141, xLessThanOnEvenPages: 525,
      yGreaterThan: 50, yLessThan: 800,
      anyOf: [{ page: 3, yLessThan: 500 }, { pageNot: 3 }],
    }),
    textItemTransformer: transformText({
      h1: { fontSize: 24, regexp: /^\d+\.(\D|$)/, fontName: 'g_d0_f2' },
      h2: { fontSize: 14, regexp: /^\d+\.\d+/, fontName: 'g_d0_f2' },
      h3: { fontSize: 14, fontName: 'g_d0_f2' },
      bold: { fontName: ['g_d0_f2', 'g_d0_f8', 'g_d0_f7'] },
      emphasis: { fontName: ['g_d0_f4', 'g_d0_f7'] },
    }),
    skip: 2,
    skipEnd: 2,
    enabled: true,
  }),
  new CudeschPDFLoader('documents/pfadi.pdf', {
    source: 'https://pfadi.swiss/media/files/9f/01_pfadi_de_web.pdf',
    documentName: PFADI,
    textItemFilter: onlyTextWhere({
      xGreaterThanOnOddPages: 70, xLessThanOnOddPages: 455,
      xGreaterThanOnEvenPages: 141, xLessThanOnEvenPages: 525,
      yGreaterThan: 50, yLessThan: 800,
      pageNot: [13, 24, 26],
      anyOf: [{ pageNot: 9 }, { page: 9, anyOf: [{ yGreaterThan: 525 }, { yLessThan: 200 }] }],
    }),
    textItemTransformer: transformText({
      h1: { fontSize: 24, regexp: /^\d+\.(\D|$)/, fontName: 'g_d0_f2' },
      h2: { fontSize: 14, regexp: /^\d+\.\d+/, fontName: 'g_d0_f2' },
      bold: { fontName: ['g_d0_f2'] },
      emphasis: { fontName: ['g_d0_f6'] },
    }),
    skip: 2,
    skipEnd: 3,
    enabled: true,
  }),
  new CudeschPDFLoader('documents/sicherheit.pdf', {
    source: 'https://pfadi.swiss/media/files/a6/08_sicherheit_de_web.pdf',
    documentName: SICHERHEIT,
    textItemFilter: onlyTextWhere({
      xGreaterThanOnOddPages: 70, xLessThanOnOddPages: 455,
      anyOf: [
        { pageNot: [14, 15, 17, 31, 45], xGreaterThanOnEvenPages: 141, xLessThanOnEvenPages: 525 },
        { page: 14, xGreaterThan: 170 },
        { page: 15, xGreaterThan: 200 },
        { page: 17, xGreaterThan: 200 },
        { page: 31, yGreaterThan: 590 },
        { page: 45, yGreaterThan: 680 },
      ],
      yGreaterThan: 50, yLessThan: 800,
      fontSizeGreaterThan: 8,
      pageNot: [7],
    }),
    textItemTransformer: transformText({
      h1: { fontSize: 24, regexp: /^\d+\.(\D|$)/, fontName: 'g_d0_f2' },
      h2: { fontSize: 14, regexp: /^\d+\.\d+/, fontName: 'g_d0_f2' },
      bold: { fontName: ['g_d0_f7', 'g_d0_f2'] },
      emphasis: { fontName: ['g_d0_f8', 'g_d0_f9', 'g_d0_f2'] },
    }),
    skip: 3,
    skipEnd: 1,
    enabled: true,
  }),
  new CudeschPDFLoader('documents/rqf.pdf', {
    source: 'https://issuu.com/pbs-msds-mss/docs/3118.01de-rqf-20160831-akom',
    documentName: RQF,
    textItemFilter: onlyTextWhere({
      xGreaterThanOnEvenPages: 170, xLessThanOnEvenPages: 505,
      xGreaterThanOnOddPages: 130, xLessThanOnOddPages: 470,
      yGreaterThan: 50, yLessThan: 800,
      pageNot: [26, 27]
    }),
    textItemTransformer: (item, previousItem) => {
      return transformText({
        h1: { fontSize: 18, fontName: 'g_d0_f2', regexp: /^\d+ / },
        h2: { fontSize: 10, fontName: 'g_d0_f2', regexp: /^\d+\.\d+ / },
        //h3: { fontSize: 10, fontName: 'g_d0_f1', regexp: /^\d+\.\d+\.\d+ /, multiline: false },
        bold: { fontName: ['g_d0_f1', 'g_d0_f5'] },
      })({ ...item, str: item.str.replaceAll('', '') }, previousItem)
    },
    skip: 6,
    skipEnd: 5,
    enabled: true,
  }),
  new CudeschPDFLoader('documents/pfaditechnik.pdf', {
    source: 'https://www.hajk.ch/de/pfaditechnik-in-wort-und-bild-pfadi-glockenhof',
    documentName: PFADITECHNIK,
    textItemFilter: onlyTextWhere({
      pageNot: [83, 112, 144, 145, 146, 147, 148, 149, 169, 170, 171],
      fontSizeGreaterThan: 8,
      xGreaterThan: 48, xLessThan: 475, yGreaterThan: 45, yLessThan: 800,
    }),
    tables: [
      { page: 34, top: 540, bottom: 307, numCols: 5, numRows: 10 },
      { page: 94, top: 525, bottom: 272, colBounds: [66, 177, 289, null], rowHeights: [2, 1, 3, 3, 2, 2, 2, 2, 2, 2] },
      { page: 98, top: 495, bottom: 387, numCols: 2, numRows: 1, renderAsTable: false },
      { page: 98, top: 313, bottom: 48, numCols: 2, numRows: 1, renderAsTable: false },
      { page: 99, top: 551, bottom: 498, left: 78, right: 422, numRows: 1, colWidths: [1, 1], renderAsTable: false },
      { page: 107, top: 369, bottom: 316, numCols: 4, numRows: 4 },
      { page: 110, top: 523, bottom: 479, left: 66, right: 409, numRows: 4, numCols: 3 },
      { page: 110, top: 243, bottom: 190, left: 66, right: 409, numRows: 4, numCols: 2 },
      { page: 114, top: 313, bottom: 232, left: 66, right: 409, numRows: 5, numCols: 3 },
      { page: 116, top: 299, bottom: 246, left: 66, right: 199, numRows: 4, numCols: 2 },
      { page: 116, top: 299, bottom: 246, left: 199, right: 409, numRows: 4, colWidths: [1, 20, 20, 20] },
      { page: 129, top: 222, bottom: 64, left: 66, right: 409, rowHeights: [1, 1, 1, 1, 2, 5, 2], numCols: 3 },
      { page: 133, top: 270, bottom: 64, left: 66, right: 409, rowHeights: [1, 2, 2, 3, 2, 2, 2], numCols: 3 },
      { page: 134, top: 229, bottom: 92, left: 66, right: 400, rowHeights: [1, 1, 1, 1, 1, 2, 2, 1], colWidths: [1, 2, 2] },
      { page: 153, top: 299, bottom: 218, left: 66, right: 400, numRows: 6, numCols: 3 },
      { page: 156, top: 383, bottom: 275, left: 66, right: 400, numRows: 6, colWidths: [1, 1] },
      { page: 159, top: 383, bottom: 232, left: 66, right: 400, numRows: 11, colWidths: [2, 2, 1] },
      { page: 163, top: 229, bottom: 92, left: 66, right: 400, numRows: 1, colWidths: [1, 1, 1], renderAsTable: false },
      { page: 164, top: 481, bottom: 209, left: 66, right: 400, rowHeights: [1, 1, 1, 1, 1, 1, 1, 1, 1, 1], colWidths: [1, 1, 1], renderAsTable: false },
      { page: 166, top: 537, bottom: 428, left: 66, right: 400, numRows: 8, numCols: 3 },
      { page: 199, top: 425, bottom: 134, left: 66, right: 400, rowHeights: [1, 1, 2, 2, 2, 2, 1, 2, 2, 1, 2, 1, 2], numCols: 2 },
      { page: 209, top: 285, bottom: 106, left: 66, right: 400, numRows: 13, colWidths: [7, 2, 3, 3] },
    ],
    textItemTransformer: transformText({
      h1: { fontSize: 16 },
      h2: { regexp: /^\d\.(\D|$)/, xLessThan: 60, colorNot: [ 207, 49, 50 ] },
      h3: { anyOf: [
        { regexp: /^\d\s?\.(\d+\.?)?(\D|$)/, xLessThan: 60, color: [ 207, 49, 50 ] },
        // In this section of the book, there are lots of small bold headings which aren't formatted
        // like the other numbered h3 headings
        { pageGreaterOrEq: 179, pageLessOrEq: 201, startOfLine: true, fontName: 'g_d0_f2' },
      ] },
      bold: { fontName: 'g_d0_f2' },
    }),
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
    template: `Putze den folgenden rohen Text heraus. Korrigiere überschüssigen oder fehlenden Whitespace, entferne Zeilenumbrüche in der Mitte von Sätzen, entferne Trennstriche von den Zeilenenden, und formatiere den Text inkl. Aufzählungen als Markdown. Gib den Text inhaltlich komplett unverändert aus. Gib nur den Markdown-Inhalt aus, ohne \`\`\`markdown etc. rundherum. Falls du in einer Tabelle unbedingt Zeilenumbrüche machen musst, verwende <br>.

Roher Text:
{context}

Herausgeputzter Markdown-Text:`,
    inputVariables: ['context']
  })
  const cleaner = LLMChainExtractor.fromLLM(llm, promptTemplate)

  return await cleaner.compressDocuments(chapters, '')
}

async function splitSemantically (content, metadata, pageBreaks, averageChapterSize = 1000) {
  const promptTemplate = new PromptTemplate({
    template: `Unterteile den gegebenen Text in ${Math.floor(content.length / averageChapterSize)} sinnvolle Kapitel. Jedes Kapitel darf unterschiedlich lang sein und mehrere Abschnitte enthalten, sollte aber mindestens 3 Sätze lang sein, für sich stehend verständlich sein (der Kontext soll klar sein) und die semantische Bedeutung beibehalten. Zwischentitel sind gute Unterteilungspunkte. Lass nichts aus, jeder Teil des Textes muss zwingend in genau einem der Kapitel enthalten sein. Gib ausschliesslich eine JSON-formatierte Liste von Unterteilungspunkten aus. Angenommen du bekommst folgenden Beispieltext:

## Verkehrsministerium beschliesst Warnwestenpflicht für Rehe und Hirsche
Auf Initiative von Verkehrsminister Volker Wissing (FDP) sollen ab kommendem Jahr sämtliche Hirscharten - darunter Rehe sowie Rot- und Damwild - zum Tragen einer Warnweste verpflichtet werden. Mit seinem Vorschlag will der Minister die Zahl der Wildunfälle drastisch senken. Unterstützt wird er dabei vom Deutschen Jagdverband, der sich schon seit Langem besser gekennzeichnetes Wild wünscht. "Leider sind die meisten Hirscharten äusserst unzuverlässig", erklärt der Forstbeamte Andreas Range, der immer wieder angefahrenen Rehen den Gnadenschuss geben muss. "Seit Jahren hängen wir kostenlose Reflektoren an den Rändern von Waldstrassen auf – meinen Sie, auch nur ein Reh hätte sich einmal einen davon umgehängt, um nachts besser gesehen zu werden? Fehlanzeige!"

Dann gib Unterteilungspunkte wie folgt formatiert aus:

[{{"chapterStart": "## Verkehrsministerium beschliesst Warnwestenpflicht für", "relationToFullText": "Warnwestenpflicht für Tiere um Wildunfälle zu senken"}}, {{"chapterStart": "\\"Leider sind die meisten Hirscharten äusserst unzuverlässig\\", erklärt", "relationToFullText": "Warnwestenpflicht für Tiere weil bisher Reflektoren von Tieren ignoriert werden"}}]

Achte darauf, den Text in "chapterStart" eindeutig zu wählen und in keiner Weise zu verändern, damit eine Maschine die Textstelle im Originaltext automatisiert finden kann. "relationToFullText" soll für sich allein genommen den Kontext / Überblick geben, worum es im Kapitel geht und welchen Teil des Gesamttextes das Kapitel abdeckt.

Metadaten zum Text:
Name des Ursprungsdokuments wo der Text herkommt: {documentName}
Hierarchie-Position (wo im Ursprungsdokument sich der Text befindet):
{hierarchy}

Zu unterteilender Text:
{content}`,
    inputVariables: ['content', 'documentName', 'hierarchy']
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
    .invoke({
      content,
      documentName: metadata.documentName,
      hierarchy: metadata.hierarchy.map((entry, index) => '#'.repeat(index + 1) + ' ' + entry).join('\n'),
    })
}

async function summarize (content, metadata) {
  const promptTemplate = new PromptTemplate({
    template: `Fasse in wenigen Stichworten zusammen (maximal 1 Satz!) worum es im gegebenen Text geht. Kein vollständiger Satz, nur Stichworte!
Hier 2 Beispiel-Zusammenfassungen von anderen Texten:
Warnwestenpflicht für Tiere weil bisher Reflektoren von Tieren ignoriert werden
Definition von Qualität bei Pfadiaktivitäten und Wege wie wir die Pfadis persönlich weiterbringen können

Metadaten zum folgenden, zusammenzufassenden Text:
Dokumentname: {documentName}
Kapitel-Hierarchie:
{hierarchy}

Gegebener Text der zusammengefasst werden soll:
{content}`,
    inputVariables: ['content', 'documentName', 'hierarchy']
  })
  const chain = RunnableSequence.from([ promptTemplate, llm ])
  return await chain.invoke({
    content,
    documentName: metadata.documentName,
    hierarchy: metadata.hierarchy.map((entry, index) => '#'.repeat(index + 1) + ' ' + entry).join('\n'),
  })
}

async function addMetadataAndSplitLargeChapters (chapters, pageBreaks) {
  const AVERAGE_CHAPTER_SIZE = 1500
  return (await Promise.all(chapters.map(async chapter => {
    if (chapter.pageContent.length > 2 * AVERAGE_CHAPTER_SIZE) {
      const pageBreaksInChapter = pageBreaks.filter((pageBreak) => {
        return pageBreak.page >= chapter.metadata.pageNumber && pageBreak.page <= chapter.metadata.endPageNumber
      })
      const splits = await splitSemantically(chapter.pageContent, chapter.metadata, pageBreaksInChapter, AVERAGE_CHAPTER_SIZE)
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
      const summary = await summarize(chapter.pageContent, chapter.metadata)
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
    if (!chapters.length) continue

    //chapters.sort((chapter1, chapter2) => chapter2.pageContent.length - chapter1.pageContent.length)
    //chapters.sort((chapter1, chapter2) => chapter1.pageContent.length - chapter2.pageContent.length)
    //chapters = [chapters[0], chapters[Math.floor(Math.random() * chapters.length - 3) + 1], chapters[chapters.length - 1]]
    //chapters = chapters.slice(0, 1)

    //console.log(chapters); return
    //console.log(chapters.map(c => c.pageContent).join('\n\n')); return
    //const index = Math.floor(Math.random() * chapters.length); console.log(chapters[index]); return // random chapter
    //console.log(chapters.map(chapter => chapter.metadata.pageNumber + '\t' + chapter.pageContent.split('\n')[0]).join('\n')); return // TOC

    console.log('cleaning', chapters.length, 'chapters')
    // Clean up all formatting artifacts from the PDF parsing process, resulting in nice Markdown text
    chapters = await splittingLargeChaptersBetweenSentences(chapters, async (chapters) => {
      return await cleanChapters(chapters)
    })

    //console.log(chapters); return

    console.log('splitting and summarizing', chapters.length, 'chapters')
    // Add keyword summaries of all chapters, and semantically split long chapters into smaller ones
    chapters = await addMetadataAndSplitLargeChapters(chapters, loader.pageBreaks)
    console.log('got', chapters.length, 'chapters ready for saving')
    //console.log(chapters); return

    if (!chapters.length) continue

    // Remove any previous stored embeddings of the same PDF
    await supabaseClient.rpc('delete_chapters_by_document', { document_name: loader.documentName })

    // Create embeddings and store them into supabase
    await vectorStore.addDocuments(chapters)
  }
  console.log('saved!')
}

await indexTheory(loaders)
