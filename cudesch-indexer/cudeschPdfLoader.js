import { PDFLoader } from 'langchain/document_loaders/fs/pdf'
import { Document } from 'langchain/document'

export class CudeschPDFLoader extends PDFLoader {
  constructor (filePathOrBlob, {
    source = null,
    documentName = filePathOrBlob,
    skip = 0,
    skipEnd = 0,
    textItemFilter = (item) => true,
    tables = [],
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
    this.tables = tables.map(tableDescriptor => new Table(tableDescriptor)),
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
      if (e.code === 'ENOENT') {
        console.log(`Cannot load ${this.filePathOrBlob}. If you want to index "${this.documentName}", please place it at that location. For now, we will just skip this document. ${e}`)
        return []
      }
      throw e
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
        .reduce((items, item) => {
          const containingTable = this.tables.find(table => table.containsItem(item))
          if (containingTable) {
            containingTable.addItem(item)
          } else {
            items.push(item)
          }
          return items
        }, [])
        .filter((item) => {
          const result = this.textItemFilter(item, previousFilterItem)
          if (result) previousFilterItem = item
          return result
        })
        .reduce((items, item, index, array) => {
          const precedingTable = this.tables.find(table => table.shouldRenderBefore(item))
          if (precedingTable) items.push(...precedingTable.toItems())

          items.push(item)

          if (index === array.length - 1) {
            const finishingTable = this.tables.find(table => table.page === item.page && table.rendered === false)
            if (finishingTable) items.push(...finishingTable.toItems())
          }
          return items
        }, [])
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

class Table {
  constructor (descriptor) {
    this.renderAsTable = descriptor.renderAsTable !== false
    this.page = descriptor.page
    this.numRows = descriptor.numRows || (descriptor.rowHeights ? descriptor.rowHeights.length : (descriptor.rowBounds ? descriptor.rowBounds.length - 1 : 1))
    this.numCols = descriptor.numCols || (descriptor.colWidths ? descriptor.colWidths.length : (descriptor.colBounds ? descriptor.colBounds.length - 1 : 1))
    this.top = descriptor.top || (descriptor.rowBounds ? descriptor.rowBounds[0] || 3000 : 3000)
    this.bottom = descriptor.bottom || (descriptor.rowBounds ? descriptor.rowBounds[descriptor.rowBounds.length - 1] || 0 : 0)
    this.left = descriptor.left || (descriptor.colBounds ? descriptor.colBounds[0] || 0 : 0)
    this.right = descriptor.right || (descriptor.colBounds ? descriptor.colBounds[descriptor.colBounds.length - 1] || 3000 : 3000)
    this.rowHeights = descriptor.rowHeights
    this.colWidths = descriptor.colWidths
    this.rowBounds = descriptor.rowBounds || []
    this.colBounds = descriptor.colBounds || []
    this.items = []
    this.rendered = false
  }

  containsItem(item) {
    return item.page === this.page &&
      item.transform[4] >= this.left && item.transform[4] < this.right &&
      item.transform[5] >= this.bottom && item.transform[5] <= this.top
  }

  shouldRenderBefore(item) {
    if (this.rendered) return false
    return item.page > this.page ||
      item.page === this.page && (
        item.transform[5] < this.top ||
        item.transform[5] === this.top && item.transform[4] >= this.right
      )
  }

  addItem (item) {
    // Heuristic: A space has a width of a little over 1/4 of the font size
    const leftCorrection = (item.str.length - item.str.trimStart().length) * item.transform[0] * 0.27
    const transform = item.transform.slice()
    transform[4] += leftCorrection
    this.items.push({ ...item, str: item.str.trimStart(), transform })
  }

  mostFrequentValues(values, n) {
    values.sort((a, b) => values.filter(v => v.toString() === a.toString()).length - values.filter(v => v.toString() === b.toString()).length)
    const mostFrequent = [...new Set(values)].slice(-n)
    mostFrequent.sort((a, b) => a - b)
    return mostFrequent
  }

  detectBounds() {
    if (!this.rowBounds.length) {
      if (this.rowHeights) {
        let cumSum = 0
        let totalHeight = this.rowHeights.reduce((sum, height) => sum + height, 0)
        this.rowBounds = [ this.top, ...this.rowHeights.map(height => {
          cumSum += height
          return this.top + ((this.bottom - this.top) * (cumSum / totalHeight))
        })]
      } else {
        this.rowBounds = [...this.mostFrequentValues(this.items.map(item => item.transform[5]), this.numRows).reverse(), this.bottom]
      }
    }
    if (!this.colBounds.length) {
      if (this.colWidths) {
        let cumSum = 0
        let totalWidth = this.colWidths.reduce((sum, width) => sum + width, 0)
        this.colBounds = [ this.left, ...this.colWidths.map(width => {
          cumSum += width
          return this.left + ((this.right - this.left) * (cumSum / totalWidth))
        })]
      } else {
        this.colBounds = [...this.mostFrequentValues(this.items.map(item => item.transform[4]), this.numCols), this.right]
      }
    }
    this.rowBounds[0] = this.top
    this.rowBounds[this.rowBounds.length - 1] = this.bottom
    this.colBounds[0] = this.left
    this.colBounds[this.colBounds.length - 1] = this.right
  }

  sortItemsIntoCells() {
    if (this.sorted) return
    this.sorted = true
    this.detectBounds()

    this.cells = Array.from({ length: this.numRows }, () => Array.from({ length: this.numCols }, () => []))
    this.items.forEach(item => {
      const row = this.rowBounds.findLastIndex(rowBound => rowBound >= item.transform[5])
      const col = this.colBounds.findLastIndex(colBound => colBound <= item.transform[4])
      this.cells[row][col].push(item)
    })
  }

  renderCell(cell) {
    return cell.map(item => item.str.trim()).join('\n').trim()
  }

  renderToMarkdownTable() {
    const renderRow = (row) => '| ' + row.map(this.renderCell).map(c => c.replaceAll('\n', ' ')).join(' | ') + ' |'
    if (this.numRows === 0) return ''
    const header = renderRow(this.cells[0])
    const separator = '|---'.repeat(this.numCols) + '|'
    const body = this.cells.slice(1).map(renderRow)
    return [header, separator, ...body].join('\n')
  }

  exampleItemFrom(items) {
    if (items.length) return items[0]
    return {
      str: '',
      dir: 'ltr',
      width: 10 * (this.right - this.left),
      height: 10 * (this.top - this.bottom),
      transform: [10, 0, 0, 0, this.left, this.top],
      fontName: 'g_d0_f1',
      page: this.page,
      odd: !!(this.page % 2),
      color: [0, 0, 0],
    }
  }

  toItems() {
    this.rendered = true
    this.sortItemsIntoCells()

    if (!this.renderAsTable) {
      return this.cells.flat().map(cell => {
        return { ...this.exampleItemFrom(cell), str: this.renderCell(cell) }
      })
    }

    return [{ ...this.exampleItemFrom(this.items), str: this.renderToMarkdownTable() }]
  }
}
