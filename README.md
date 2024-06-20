# pfadi.ai - AI tools for Swiss scouts

[pfadi.ai](https://pfadi.ai) is a collection of AI use cases and showcases for scouting.

So far we have the following tools:

- **[pfadinamen.app](https://pfadinamen.app)**, which is maintained in a [separate repository](https://github.com/carlobeltrame/pfadinamen), is a [char-rnn](http://karpathy.github.io/2015/05/21/rnn-effectiveness/) trained on scout names, which can generate novel scout names and look up the meanings of similar known names on [pfadinamen.ch](https://pfadinamen.ch).
- **[samstag](https://pfadi.ai/samstag)** (code in [`/samstag`](https://github.com/carlobeltrame/pfadi.ai/tree/main/samstag)) is an LLM chain (currently based on GPT-4o) for generating stories and programme for weekly scouting events.
- **[LA](https://pfadi.ai/la)** (code in [`/la`](https://github.com/carlobeltrame/pfadi.ai/tree/main/la)) is an LLM chain (currently based on GPT-4o) for generating scaffolds and programme for J+S Lageraktivitäten in J+S camps.
- **[kursblock](https://pfadi.ai/kursblock)** (code in [`/kursblock`](https://github.com/carlobeltrame/pfadi.ai/tree/main/kursblock)) is an LLM chain (currently based on GPT-4o) for generating goals and programme for scouting courses.
- **[cudesch](https://pfadi.ai/cudesch)** (code in [`/cudesch`](https://github.com/carlobeltrame/pfadi.ai/tree/main/cudesch) and [`/cudesch-indexer`](https://github.com/carlobeltrame/pfadi.ai/tree/main/cudesch-indexer)) is a search engine providing a semantic search over a selection of brochures and other literature which is commonly used in scout courses.

If you have an idea for a new tool or want to help by providing more example data, feel free to contact me at: cosinus at gryfensee dot ch.

For now, all the tools are only available in Swiss German, because I only have access to and experience with German programme. If you would like to help build versions in other languages, this would be greatly appreciated.

## Architecture

The code is deliberately kept simple and currently contains lots of code duplication between the tools. No special knowledge except a little HTML, JavaScript and PHP are needed to understand how most of these tools are built. Also, self-hosting on a shared hosting provider is easily possible this way. However, in the future it's well possible that we introduce frameworks such as Laravel or Vue.js, depending on how these tools develop over time.

### pfadinamen.app

This is a static webpage which serves a pre-trained char-rnn. The neural network is run directly in the browser using [tensorflow.js](https://www.tensorflow.org/js). For this reason, there are no hosting or evaluation costs incurred. You can use this as much as you want free of charge, even without internet connection.

### samstag, LA and kursblock

These tools share a very similar architecture. They work by issuing a chat completion API call to the ChatGPT API (specifically GPT-4o) which includes the user input and 1-2 desired examples. This API call is authenticated via a (secret) API token, which is why these tools have PHP backend components. The responses from the API are streamed to the frontend (using [server sent events](https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events)) and displayed there. A history of old generations is stored in the client's local storage, and all generated content is also stored in a database using [PDO](https://www.php.net/manual/en/book.pdo.php), for later analysis and cost control.

There are three LLM tricks at work here:
- [Few-shot learning](https://arxiv.org/abs/2005.14165): Modern LLMs have fantastic abilities to imitate example texts. By providing just one or two examples for the desired result texts, the LLM can create similarly formatted texts. This is both way more effective and way cheaper than training or fine-tuning an LLM. Previous versions of the samstag and kursblock AI tools, which used a fine-tuned GPT-3.5, yielded texts written using scout lingo, but extremely incoherent contents.
- [Chaining multiple LLM calls](https://arxiv.org/abs/2201.11903) in order to gradually build up complex creative content: Generating long texts according to a specification is hard. But we can do it in small steps: Given a title, write a story. Then, given the title and that generated story, write a full programme. This way, the individual steps become manageable for the LLM.
- In the case of kursblock, [retrieval-augmented generation](https://arxiv.org/abs/2005.11401): We can pass in necessary domain knowledge with our prompts, so the LLM has that knowledge available during generation. We can retrieve the necessary knowledge by performing a semantic search over all possibly relevant knowledge. One component of LLMs, the [embeddings](https://en.wikipedia.org/wiki/Word_embedding), can actually help us create great semantic searches.

### cudesch

This tool implements a semantic search over a selection of PDF files relevant in scout courses. The heavy lifting is actually done long before the search starts: The code in [`/cudesch-indexer`](https://github.com/carlobeltrame/pfadi.ai/tree/main/cudesch-indexer) allows to:
1. Extract the text from the PDF files using [pdf.js](https://mozilla.github.io/pdf.js/)
2. Clean it up into easily readable markdown text using a customized [langchain document compressor](https://js.langchain.com/docs/modules/data_connection/retrievers/how_to/contextual_compression)
3. Semantically split it into reasonably-sized chapters using an LLM call with a [custom text splitting algorithm](https://github.com/carlobeltrame/pfadi.ai/blob/main/cudesch-indexer/textSplitOutputParser.js)
4. Summarize each of these chapters using an LLM call, in order to [help the later steps](https://arxiv.org/pdf/2204.10019.pdf#section.3) to focus on the important aspects and ignore unimportant words
5. Create an [embedding vector](https://en.wikipedia.org/wiki/Word_embedding) of each summary (representing the "meaning" as a list of numbers)
6. Save the embeddings into a Supabase vector database

Once that is done, in Supabase we expose the list of indexed PDF documents in a REST endpoint, as well as a search function.

To perform an actual search, the code in [`/cudesch`](https://github.com/carlobeltrame/pfadi.ai/tree/main/cudesch) first calculates an embedding of the search terms, and then sends that embedding (along with some optional filters) to the search function. The search function compares the query embedding with the stored chapter summary embeddings, and returns the chapters which are semantically closest to the search terms. The text content of these closest chapters is then returned to the user, and is ready to be used as literature in the retrieval-augmented generation mechanism of the kursblock tool.

## Running locally

### pfadinamen.app
If you want to run the pfadinamen.app tool locally, after checking out this repo you have to initialize the submodule:
```bash
git submodule init && git submodule update
```

Advanced: In case the pfadinamen.app repo has new commits, you can update the submodule reference using `git submodule update --remote`.

### samstag, LA and kursblock
For the samstag, LA and kursblock tools, you will need an [OpenAI API key](https://help.openai.com/en/articles/4936850-where-do-i-find-my-api-key). Create a copy of samstag/.env.example called samstag/.env (and vice versa for LA and kursblock) and fill in your API key in there.

**Note:** To have access to the GPT-4 and GPT-4o API, currently you will need to have made a successful payment of [at least 5 USD](https://help.openai.com/en/articles/7102672-how-can-i-access-gpt-4-gpt-4-turbo-and-gpt-4o) to OpenAI. If you don't have such an OpenAI account, you can change the model name in your .env files to `gpt-3.5-turbo-1106`, but you might face worse output quality with GPT-3.5 Turbo as opposed to GPT-4o.

Next, you will have to install the PHP dependencies:
```bash
(cd samstag && composer install)
(cd la && composer install)
(cd kursblock && composer install)
```

If you want to test or set up the database saving part of the tools, you will have to run a MySQL or MariaDB database locally and fill in the credentials in your .env files.
Inside the database, execute the SQL scripts samstag/db-setup.sql, la/db-setup.sql and kursblock/db-setup.sql. For now, due to the lack of a framework, we don't have a migration mechanism. So if something about these SQL script is changed upstream, you will have to apply these changes manually to your database.

### cudesch

For the cudesch tool, you will need an [OpenAI API key](https://help.openai.com/en/articles/4936850-where-do-i-find-my-api-key) as well as a [free Supabase project](https://supabase.com/pricing). Create a copy of cudesch/.env.example called cudesch/.env and fill in your OpenAI API key as well as the Supabase project ID and Supabase anonymous API key in there.

Next, you will have to install the Node and PHP dependencies:
```bash
(cd cudesch-indexer && yarn)
(cd cudesch && composer install)
```

Also, in the cudesch-indexer tool, you'll have to link your local project to your free Supabase project and apply the database structure:
```bash
cd cudesch-indexer
npx supabase login
npx supabase link
npx supabase db push
cd ..
```

If you pull a newer version of this repo and get new migrations in cudesch-indexer/supabase/migrations, run these steps above again to apply the most up-to-date version of the migrations to your Supabase project.

Due to licensing issues, the PDF files are not distributed with this repository. You will have to download them yourself and place them in the /cudesch-indexer/documents directory. Name the files according to the code where `CudeschPDFLoader` is used.

To index the documents which are configured in the code, you can run:
```bash
(cd cudesch-indexer && node app.js)
```

### Final steps
Once you have set up the tools you want, you can serve the website using any webserver which supports PHP. If you have docker installed, an easy way to do that is opening a terminal in the root of this repository and executing:
```bash
docker run -i --rm -v "$(pwd)":/var/www/html:ro --network host php:8-apache
```

Then, you can visit your local version of the site at [http://localhost](http://localhost).

## Self-hosting

Self-hosting is almost the same as running locally. But instead of the final steps, simply upload everything except /cudesch-index to your hosting of choice. Even a shared PHP hosting will do. Make sure to use a PHP version >= 8.
