import { OpenAIChat } from 'langchain/llms/openai'

/*
Cleans any LLM output by replacing ß with ss, and trimming whitespace off the beginning and end.
 */
export class SwissGermanLLM extends OpenAIChat {
  constructor(...options) {
    super(...options)
  }

  postprocessContent(content) {
    if (!content) return content
    return content
      .trim()
      .replaceAll('ß', 'ss')
      .replaceAll(/\b([Jj])&[Ss]\b/g, '$1+$3')
  }

  async completionWithRetry(request, options) {
    const original = await super.completionWithRetry(request, options)
    if (options.stream) {
      throw new Error('Not implemented: Streaming SwissGermanLLM is not supported. Got request', request, 'with options', options)
      // return original
    }
    return {
      ...original,
      choices: original.choices.map(choice => {
        return {
          ...choice,
          message: {
            ...choice.message,
            content: this.postprocessContent(choice.message.content),
          }
        }
      }),
    }
  }
}
