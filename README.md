# Drupal AI Example

Working example of the [Drupal AI](https://www.drupal.org/project/ai) module ecosystem, built around three core pillars — with real gotchas found and documented along the way.

Built as a live demo for a team presentation on Drupal + AI.

## Three pillars

The core walkthrough, config-first, almost no custom code:

1. **A default chat assistant, out of the box** — `ai_assistant_api` + `ai_chatbot` (Deep Chat). Secrets via `key`, one provider, one Assistant config entity, one block.
2. **That same assistant + a vector DB** — `ai_search` + `ai_vdb_provider_pinecone`. Index site content, attach a `rag_action`, the assistant answers from real content instead of training data.
3. **Custom plugins** — `ai_example_tools`: an `AiAssistantAction` plugin (`ListRecentContent`) for the Site Assistant, plus two generic `AiFunctionCall` plugins for `ai_agents`/`ai_search` (`CreateTaggedContent`, `SiteContentStatistics`). The main extension point for site-specific logic.

Everything below this section is also in the repo and still fully working, but it's extra — not part of the three-pillar walkthrough: an AI CKEditor button, text-to-action agents, a context/governance layer, and demo content.

## Everything built here (pillars + extras)

| Feature | Module(s) |
|---|---|
| Drupal 11 scaffold | — |
| Secrets from env vars | `key` |
| LLM provider | `ai`, `ai_provider_openai` |
| Chat assistant + widget | `ai_assistant_api`, `ai_chatbot` |
| Custom callable tool | `ai_example_tools` (custom) |
| RAG over content | `ai_search`, `ai_vdb_provider_pinecone` |
| AI editor button | `ai_ckeditor` |
| Text-to-action agent | `ai_agents`, `ai_agents_explorer` |
| Context/governance layer | `ai_context` |
| Demo content (types/taxonomies/media) | `media` |

The site runs the stock Deep Chat widget (`olivero_site_assistant_deepchat`).

## Running it

Requires [DDEV](https://ddev.com/).

```
ddev start
ddev composer install
```

Set your API keys in `.ddev/config.local.yaml` (gitignored, not committed):

```yaml
#ddev-generated
web_environment:
    - OPENAI_API_KEY=sk-...
    - PINECONE_API_KEY=pcsk_...
```

Then:

```
ddev restart
ddev drush cim -y   # import config (installs and configures every module)
```

Site is at the URL `ddev describe` prints. Log in with `ddev drush uli`.

The Pinecone index (`drupal-ai-example`, 1536-dim, cosine, serverless AWS us-east-1) must exist before `ai_vdb_provider_pinecone` will validate — create it via the [Pinecone console](https://app.pinecone.io/) or its API if starting from scratch.

Then apply the demo content recipe (case studies, FAQs — content only; the content types/taxonomies/fields it needs already came in with `drush cim` above):

```
ddev drush recipe:apply recipes/ai_example_demo_content -y
```

## What to try

**The three pillars:**

- **Site Assistant, out of the box** — floating chat widget, bottom of any page. Ask something open-ended and it answers from the model directly — no custom code, no retrieval.
- **Site Assistant + vector DB** — ask "According to the site content, what does the drupal/ai module provide?" to see it pull real chunks from the Pinecone RAG index instead of just its training data.
- **Custom plugin in action** — ask "What content is on this site?" — it calls the custom `list_recent_content` tool and answers with real node titles/dates/links, not a hallucinated list.

**Extras also in the repo:**

- **AI Agent Explorer** (`/admin/config/ai/agents/explore`) — pick "Taxonomy Agent", ask it to create a vocabulary or add terms. Real text-to-action: it edits the database, not just chat text.
- **CKEditor Summarize** — edit any Article/Page body, select text, use the "AI Assistant" toolbar button → Summarize.
- **Context Control Center** (`/admin/config/ai/context/items`) — one context item is live (a taxonomy style-guide rule). Ask the Taxonomy Agent to add a term and check its description — it will be prefixed `"For the presentation:"`, proving the injected context actually changed the output.
- **Case Study / FAQ content** — two custom content types with real sample content (`drush recipe:apply recipes/ai_example_demo_content`), indexed into the same Pinecone vector index as everything else with zero extra config: the `ai_search` `indexing_options` are keyed by field name, not bundle, so new bundles sharing the standard `body`/`title` fields are picked up automatically.

## Custom code

- `web/modules/custom/ai_example_tools/` — one `AiAssistantAction` plugin (`ListRecentContent`) exposing a read-only node query as a tool the Site Assistant can call, plus two generic `AiFunctionCall` plugins for `ai_agents`/`ai_search`: `CreateTaggedContent` (creates a content item with a title and a taxonomy tag, discovering the target bundle's taxonomy-reference field dynamically and reusing an existing term by name instead of duplicating it) and `SiteContentStatistics` (reports real content/taxonomy counts, optionally scoped to one content type — a read-only tool so the model reports a real number instead of guessing one). `CreateTaggedContent` needed a taxonomy field on Article to demo against — added `field_tags` (entity reference to the pre-existing, previously-unused `tags` vocabulary).
- `recipes/ai_example_demo_content/` — a Drupal recipe shipping the actual demo **content**: Case Study/FAQ nodes, Industries/FAQ Categories taxonomy terms, and image media, exported with `drush content:export --with-dependencies` into the core `content/<entity_type>/<uuid>.yml` format. It ships no config at all — the content types/fields/taxonomies/module-enablement those entities depend on live in `config/sync` like the rest of the site (`drush cim`) and must already be applied first. Reapplying the recipe is idempotent (matched by UUID, no duplicates).

Everything else in this project is configuration.
