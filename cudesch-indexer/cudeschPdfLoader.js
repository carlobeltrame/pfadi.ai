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
      const { default: mod } = await import("pdf-parse/lib/pdf.js/v1.10.100/build/pdf.js");
      this.OPS = mod.OPS
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

  /**
   * Mocks the parts of the DOM document object needed for page.getOperatorList() to complete
   */
  mockDocument() {
    globalThis.document = {
      createElement(...args) {
        return {
          sheet: {
            insertRule() {},
            cssRules: [],
          }
        }
      },
      documentElement: {
        getElementsByTagName(tagName) {
          return [{
            appendChild() {}
          }]
        }
      }
    }
  }

  getItemColor(item, operatorList) {
    // Stack for recording states
    const stack = []
    // Current state record
    let currentStatus = {}

    //  Analyze the page instructions in order
    for (let fnIndex = 0; fnIndex < operatorList.fnArray.length; fnIndex++) {
      const fn = operatorList.fnArray[fnIndex]
      const args = operatorList.argsArray[fnIndex]
      switch (fn) {
        //push currentStatus to stack
        case this.OPS.save:
          stack.push(currentStatus)
          currentStatus = { ...currentStatus }
          break
        //restore currentStatus from stack
        case this.OPS.restore:
          currentStatus = stack.pop() ?? {}
          break
        //Set text fill color
        case this.OPS.setFillRGBColor:
          currentStatus.currentColor = [args[0], args[1], args[2]]
          break
        //Set text area
        case this.OPS.setTextMatrix:
          currentStatus.currentMatrix = [args[4], args[5]]
          currentStatus.currentXY = [args[4], args[5]]
          break
        //Set line spacing
        case this.OPS.setLeading:
          currentStatus.leading = args[0]
          break
        //Set font type and size
        case this.OPS.setFont:
          currentStatus.font = [args[0], args[1]]
          break
        //Calculate line break, when line break occurs, the current coordinates need to jump to the beginning of the next line
        case this.OPS.nextLine:
        case this.OPS.nextLineShowText:
        case this.OPS.nextLineSetSpacingShowText:
          if (currentStatus.leading && currentStatus.currentXY) {
            currentStatus.currentXY = [currentStatus.currentXY[0], currentStatus.currentXY[1] - currentStatus.leading]
          }
          break
        // Move text coordinates
        case this.OPS.moveText:
          if (currentStatus.currentXY) {
            currentStatus.currentXY = [currentStatus.currentXY[0] + args[0], currentStatus.currentXY[1] + args[1]]
          }
          break
        //Show text
        case this.OPS.showText:
          if (currentStatus.currentXY) {
            let x = currentStatus.currentXY[0]
            let y = currentStatus.currentXY[1]
            // Check if the text matches the position
            const isMatch = () =>
              Math.abs(x - item.transform[4]) < item.height / 5 && Math.abs(y - item.transform[5]) < item.height / 5
            if (isMatch()) {
              return currentStatus.currentColor
            }
            if (args[0]) {
              // Calculate the actual coordinates of each printed character, and then match them with the coordinates of the item
              for (let charInfo of args[0]) {
                if (typeof charInfo?. width == 'number' && currentStatus.font) {
                  if (isMatch()) {
                    return currentStatus.currentColor
                  }
                  x += (charInfo?. width / 1000) * currentStatus.font[1]
                } else if (typeof charInfo == 'number' && currentStatus.font) {
                  if (isMatch()) {
                    return currentStatus.currentColor
                  }
                  x -= (charInfo / 1000) * currentStatus.font[1]
                }
              }
            }
          }
          break
      }
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
    let chapterNumber = 0
    let nonHeaderLineFound = false

    const completeChapter = (hierarchy) => {
      if (!nonHeaderLineFound) {
        chapterItems.pop()
        return
      }
      if (chapterItems.length > 1) {
        documents.push(new Document({
          pageContent: chapterItems.map((item) => item.str).join('\n'),
          metadata: {
            ...metadata,
            documentName: this.documentName,
            hierarchy,
            pageNumber: chapterItems[0].page,
            endPageNumber: chapterItems[chapterItems.length - 1].page,
            chapterNumber: chapterNumber++,
            sequenceNumber: 0,
            totalPages: pdf.numPages,
            source: this.source,
          }
        }))
      }
      chapterItems = []
      nonHeaderLineFound = false
    }

    let previousItem = null
    let previousFilterItem = null
    let previousTransformerItem = null
    let hierarchy = []
    for (let i = 1 + this.skip; i <= pdf.numPages - this.skipEnd; i += 1) {
      const page = await pdf.getPage(i)
      const content = await page.getTextContent()
      this.mockDocument()
      const operatorList = await page.getOperatorList()

      if (content.items.length === 0) continue
      if (!previousFilterItem) previousFilterItem = content.items[0]
      if (!previousTransformerItem) previousTransformerItem = content.items[0]

      const items = content.items
        .map(item => ({ ...item, page: i, odd: !!(i % 2), color: this.getItemColor(item, operatorList) }))
        .sort((a, b) => {
          if (a.transform[5] !== b.transform[5]) return b.transform[5] - a.transform[5]
          return a.transform[4] - b.transform[4]
        })
        .filter((item) => {
          const result = this.textItemFilter(item, previousFilterItem)
          if (result) previousFilterItem = item
          return result
        })
        .map((item) => {
          const result = this.textItemTransformer(item, previousTransformerItem)
          previousTransformerItem = result
          return result
        })
        .reduce((items, item) => {
          if (item.joinWithPrevious && items.length > 0) {
            // Join and remove multiple consecutive whitespaces
            items[items.length - 1].str = (items[items.length - 1].str + ' ' + item.str).replaceAll(/\s+(?=\s)/g, '')
          } else {
            items.push(item)
          }
          return items
        }, [])

      if (items.length === 0) continue
      if (!previousItem) previousItem = items[0]
      this.pageBreaks.push({ page: i, startText: items[0].str.trim() })
      items.forEach((item) => {
        if (item.heading) {
          completeChapter(hierarchy)
          const level = item.heading
          hierarchy = hierarchy.slice(0, Math.max(level - 1, 0))
          hierarchy.push(item.str)
        } else {
          nonHeaderLineFound = true
        }
        chapterItems.push(item)
        previousItem = item
      })
    }
    completeChapter(hierarchy)

    return documents
  }
}
