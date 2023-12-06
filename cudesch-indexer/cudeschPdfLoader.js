import { PDFLoader } from 'langchain/document_loaders/fs/pdf'
import { Document } from 'langchain/document'

export class CudeschPDFLoader extends PDFLoader {
  constructor (filePathOrBlob, {
    source = null,
    documentName = filePathOrBlob,
    skip = 0,
    skipEnd = 0,
    textItemFilter = (item) => true,
    textItemTransformer = (item) => true,
    pdfjs = undefined,
    enabled = true,
  }) {
    super(filePathOrBlob, { pdfjs })
    this.source = source
    this.documentName = documentName
    this.skip = skip
    this.skipEnd = skipEnd
    this.textItemFilter = textItemFilter
    this.textItemTransformer = textItemTransformer
    this.enabled = enabled
    this.pageBreaks = []
  }

  async load() {
    try {
      if (!this.enabled) {
        console.log('not loading', this.documentName, 'because its loader is disabled')
        return []
      }
      console.log('loading', this.documentName)
      return await super.load()
    } catch (e) {
      console.log(`Cannot load ${this.filePathOrBlob}. If you want to index "${this.documentName}", please place it at that location. For now, we will just skip this document. ${e}`)
      return []
    }
  }

  async parse (raw, metadata) {
    this.pageBreaks = []
    const { getDocument } = await this.pdfjs()
    const pdf = await getDocument({
      data: new Uint8Array(raw.buffer),
      useWorkerFetch: false,
      isEvalSupported: false,
      useSystemFonts: true
    }).promise
    const documents = []
    let chapterItems = []
    let chapterStartPageNumber = 1 + this.skip
    let nonHeaderLineFound = false

    const completeChapter = (previousPageNumber, currentPageNumber, chapterNumber) => {
      if (!nonHeaderLineFound) return
      if (chapterItems.length > 1) {
        documents.push(new Document({
          pageContent: chapterItems.map((item) => item.str).join('\n'),
          metadata: {
            ...metadata,
            totalPages: pdf.numPages,
            documentName: this.documentName,
            source: this.source,
            pageNumber: chapterStartPageNumber,
            endPageNumber: previousPageNumber,
            chapterNumber: chapterNumber,
            sequenceNumber: 0,
          }
        }))
      }
      chapterItems = []
      chapterStartPageNumber = currentPageNumber
      nonHeaderLineFound = false
    }

    let chapterNumber = 0
    let previousPageNumber = 1 + this.skip
    for (let i = 1 + this.skip; i <= pdf.numPages - this.skipEnd; i += 1) {
      const page = await pdf.getPage(i)
      const content = await page.getTextContent()
      const items = content.items
        .map(item => ({ ...item, page: i, odd: i % 2 }))
        .sort((a, b) => {
          if (a.transform[5] !== b.transform[5]) return b.transform[5] - a.transform[5]
          return a.transform[4] - b.transform[4]
        })
        .filter(this.textItemFilter)
        .map(this.textItemTransformer)
      if (items.length === 0) {
        continue
      }
      this.pageBreaks.push({ page: i, startText: items[0].str.trim() })
      items.forEach((item) => {
        if (item.str.match(/^#+ /)) {
          completeChapter(previousPageNumber, i, chapterNumber++)
        } else {
          nonHeaderLineFound = true
        }
        previousPageNumber = i
        chapterItems.push(item)
      })
    }
    completeChapter(
      pdf.numPages - this.skipEnd,
      pdf.numPages - this.skipEnd + 1,
      chapterNumber++
    )

    return documents
  }
}
