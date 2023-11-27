# pfadi.ai - AI tools for Swiss scouts

[pfadi.ai](https://pfadi.ai) is a collection of AI use cases and showcases for scouting.

So far we have the following tools:

- **[pfadinamen.app](https://pfadinamen.app)**, which is maintained in a [separate repository](https://github.com/carlobeltrame/pfadinamen), is a [char-rnn](http://karpathy.github.io/2015/05/21/rnn-effectiveness/) trained on scout names, which can generate novel scout names and look up the meanings of similar known names on [pfadinamen.ch](https://pfadinamen.ch).
- **[samstag](https://pfadi.ai/samstag)**, which is maintained in the [samstag](https://github.com/carlobeltrame/pfadi.ai/tree/main/samstag) directory, is an LLM chain (currently GPT-4-based) for generating stories and programme for weekly scouting events.
- **[kursblock](https://pfadi.ai/kursblock)**, which is maintained in the [kursblock](https://github.com/carlobeltrame/pfadi.ai/tree/main/kursblock) directory, is an LLM chain (currently GPT-4-based) for generating goals and programme for scouting courses.

If you have an idea for a new tool or want to help by providing more example data, feel free to contact me at: cosinus at gryfensee dot ch.

## Architecture

The code is deliberately kept simple and currently contains lots of code duplication between the tools. No special knowledge except a little HTML, JavaScript and PHP are needed to understand how these tools are built. Also, self-hosting on a shared hosting provider is easily possible this way. However, in the future it's well possible that we introduce frameworks such as Laravel or Vue.js, depending on how these tools develop over time.

### pfadinamen.app

This is a static webpage which serves a pre-trained char-rnn. The neural network is run directly in the browser using [tensorflow.js](https://www.tensorflow.org/js). For this reason, there are no hosting or evaluation costs incurred. You can use this all you want.

### samstag and kursblock

These two tools have a very similar architecture. They work by issuing a single chat completion API call to the ChatGPT API (specifically GPT-4) which includes the user input and 1-2 desired examples. This API call is authenticated via a (secret) API token, which is why these tools have PHP backend components. The responses from the API are streamed to the frontend (using [server sent events](https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events)) and displayed there. A history of old generations is stored in the client's local storage, and all generated content is also stored in a database using PDO, for later analysis and cost control.

There are two LLM tricks at work here:
- Few-shot learning: Modern LLMs have fantastic abilities to imitate example texts. By providing just one or two examples for the desired result texts, the LLM can create similarly formatted texts. This is both way more effective and way cheaper than training or fine-tuning an LLM. Previous versions of the samstag and kursblock AI tools, which used a fine-tuned GPT-3.5, yielded texts written using scout lingo, but extremely incoherent contents.
- Gradually building up content: Generating long texts according to a specification is hard. But by doing it in small steps, e.g. given a title write a story, and then given the title and that story write a full programme, the individual steps become manageable for the LLM.

## Running locally

First of all, for the samstag and kursblock tools, you will need an [API key](https://help.openai.com/en/articles/4936850-where-do-i-find-my-api-key). Create a copy of samstag/.env.example called samstag/.env (and vice versa for kursblock) and fill in your API key in there.

Next, you will have to install the PHP dependencies:
```bash
(cd samstag && composer install)
(cd kursblock && composer install)
```

Finally, you can serve the website using any webserver which supports PHP. If you have docker installed, an easy way to do that is opening a terminal in the root of this repository and executing:
```bash
docker run -i --rm -v "$(pwd)":/var/www/html:ro --network host php:8-apache
```

Then, you can visit your local version of the site at [http://localhost](http://localhost).

If you want to test or set up the database saving part of the tools, you will have to run a MySQL or MariaDB database locally and fill in the credentials in your .env files.
Inside the database, execute the two SQL scripts samstag/db-setup.sql and kursblock/db-setup.sql. For now, due to the lack of a framework, we don't have a migration mechanism. So if something about these SQL script is changed upstream, you will have to apply these changes manually to your database.

## Self-hosting

Self-hosting is almost the same as running locally. Create and fill .env files, and install the PHP dependencies. Then, simply upload everything to your hosting of choice. Even a shared PHP hosting will do. Make sure to use a PHP version >= 8.
