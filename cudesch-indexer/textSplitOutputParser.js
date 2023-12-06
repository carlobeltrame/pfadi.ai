import { StructuredOutputParser } from 'langchain/output_parsers'
import { z } from 'zod'

export class TextSplitOutputParser extends StructuredOutputParser {
  static lc_name() {
    return "TextSplitOutputParser";
  }

  constructor(content, pageBreaks) {
    super(z.array(
      z.object({
        chapterStart: z.string(),
        relationToFullText: z.string(),
      })
    ));
    this.content = content
    this.chapterPageNumber = pageBreaks[0].page
    this.chapterEndPageNumber = pageBreaks[pageBreaks.length - 1].page
    this.pageBreakOffsets = this.findPageBreakOffsets(content, pageBreaks)
  }

  async parse(text) {
    const parsed = await super.parse(text)
    let content = this.content
    const splits = parsed.map((split, index) => {
      const nextSplitStart = index + 1 < parsed.length ?
        parsed[index + 1].chapterStart?.trim().replace(/\.\.\.|…$/, '') :
        ''
      let end = nextSplitStart ? content.indexOf(nextSplitStart) : undefined
      if (end === -1) {
        // Retry with only a single line of text, since we often seem to have problems with newlines...
        const correctedNextSplitStart = nextSplitStart.split('\n')[0].trim()
        end = correctedNextSplitStart ? content.indexOf(correctedNextSplitStart) : undefined
      }
      if (end === -1) throw new Error(`Couldn't find "${nextSplitStart}" in remaining content "${content.slice(0, 2000)}..."`)
      const splitContent = content.slice(0, end)
      content = content.slice(splitContent.length)

      return {
        content: splitContent.trim(),
        summary: split.relationToFullText?.trim() || splitContent.trim(),
        length: splitContent.length,
      }
    })

    return this.addSplitPageNumbers(splits)
  }

  findPageBreakOffsets(content, pageBreaks) {
    let remainingContent = content
    let offset = 0
    const pageBreakOffsets = pageBreaks
      .map((pageBreak, pageIndex) => {
        if (pageIndex === 0) {
          // On the first page of the chapter, we force offset to be 0,
          // because many chapters will start in the middle of the first page, not at the start.
          // In this case, there is page content even before our given content.
          // Since offset means "within the chapter content, at which position does the page start?",
          // we always set offset to 0 for the first page.
          return { ...pageBreak, offset: 0 }
        }

        // Find position of page start within content
        let index = remainingContent.indexOf(pageBreak.startText)

        if (index === -1) {
          // Skip page starts which we cannot find
          return null
        }

        offset += index
        const result = { ...pageBreak, offset }

        // Remove the page from the content, so we can start looking for the next page in the remaining content
        remainingContent = remainingContent.slice(index + pageBreak.startText.length)
        offset += pageBreak.startText.length

        return result
      })
      .filter(pageBreak => pageBreak) // filter out skipped page starts

    // Add a dummy last page break after the last page
    pageBreakOffsets.push({ page: this.chapterEndPageNumber + 1, startText: '', offset: content.length })

    return pageBreakOffsets
  }

  addSplitPageNumbers (splits) {
    let cumulatedSplitLengths = 0
    let pageNumber = this.chapterPageNumber
    let endPageNumber = pageNumber

    return splits.map((split) => {
      cumulatedSplitLengths += split.length
      const nextPageIndex = this.pageBreakOffsets.findIndex(pageBreak => pageBreak.offset >= cumulatedSplitLengths)
      const endPage = this.pageBreakOffsets[nextPageIndex - 1]
      let nextPage = this.pageBreakOffsets[nextPageIndex]

      if (endPage === undefined) {
        console.log('\n\nendPage is undefined. nextPageIndex', nextPageIndex, '\ncumulatedSplitLengths: ', cumulatedSplitLengths, '\npageBreakOffsets:', this.pageBreakOffsets, '\nsplit:', split, '\nall splits:', splits.map(split => ({ ...split, content: split.content.slice(0, 30) + ' [...] ' + split.content.slice(-30) })))
      }

      endPageNumber = endPage.page
      const result = { ...split, pageNumber, endPageNumber }

      // Prepare for the next iteration
      pageNumber = endPageNumber
      if (nextPage.offset === cumulatedSplitLengths) {
        // The split ends exactly at the end of the endPage, so the next split starts on the next page
        pageNumber = nextPage.page
      }

      return result
    })
  }
}
