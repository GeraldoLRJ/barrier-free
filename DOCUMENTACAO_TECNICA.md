# Barrier Free — Documentação Técnica

> Assistente de acessibilidade web controlado por voz, voltado a pessoas cegas ou com deficiência visual severa. Uma **extensão do Chrome** captura a página, um **backend Laravel** a sanitiza, consulta **IAs/APIs externas** e devolve um texto pronto para ser **lido em voz alta**.

---

## Sumário

1. [Visão geral](#1-visão-geral)
2. [Stack tecnológica](#2-stack-tecnológica)
3. [Arquitetura](#3-arquitetura)
4. [Estrutura de diretórios](#4-estrutura-de-diretórios)
5. [Extensão Chrome](#5-extensão-chrome-extensao)
6. [Backend Laravel](#6-backend-laravel-backend)
7. [Fluxos detalhados](#7-fluxos-detalhados)
8. [Contrato da API](#8-contrato-da-api)
9. [Infraestrutura Docker](#9-infraestrutura-docker)
10. [Configuração e variáveis de ambiente](#10-configuração-e-variáveis-de-ambiente)
11. [Segurança e guardrails](#11-segurança-e-guardrails)
12. [Resiliência e tratamento de falhas](#12-resiliência-e-tratamento-de-falhas)
13. [Testes](#13-testes)
14. [Como executar](#14-como-executar)
15. [Pontos de atenção e dívidas técnicas](#15-pontos-de-atenção-e-dívidas-técnicas)

---

## 1. Visão geral

O usuário fala comandos em português. A extensão reconhece a fala, decide o que fazer e, quando precisa de inteligência, envia o conteúdo da página (DOM) ao backend. O backend transforma o HTML em um texto estruturado, pede à IA (Google Gemini) uma resposta adequada para áudio, e a extensão a reproduz por síntese de voz.

### Comandos de voz suportados

| Comando (palavras-chave) | Precisa do DOM? | O que faz |
|---|---|---|
| `resumir`, `resumo` | Sim | Resume a página e cita seções/links acessíveis |
| `orientar`, `orientação`, `ajudar`, `ajuda` | Sim | Explica como usar/navegar a página |
| `buscar`, `pesquisar`, `procurar` | Não | Pesquisa na web (Brave Search) e resume com fontes numeradas |
| `abrir`, `navegar`, `ir para`, `ir ao` | Não (usa memória/DOM local) | Navega para link da página, fonte de busca ou site conhecido |
| `comandos` | Não | Lê a lista de comandos disponíveis |

---

## 2. Stack tecnológica

| Camada | Tecnologia |
|---|---|
| Extensão | Chrome Extension **Manifest V3**, JavaScript puro (Service Worker) |
| Voz (entrada) | **Web Speech API** — `SpeechRecognition` (`pt-BR`) |
| Voz (saída) | **Web Speech API** — `SpeechSynthesis` (TTS) |
| Efeitos sonoros | **Web Audio API** (osciladores) |
| Backend | **PHP 8.2** + **Laravel 12** |
| Autenticação (preparada) | Laravel Sanctum (instalado, ainda não aplicado à rota) |
| Banco de dados | **PostgreSQL 15** (provisionado; sem uso funcional ainda) |
| Servidor web | **Nginx (alpine)** + **PHP-FPM** |
| IA generativa | **Google Gemini** (`generativelanguage.googleapis.com`) |
| Busca web | **Brave Search API** |
| Segurança de URL | **Google Safe Browsing v4** |
| Contêineres | Docker + Docker Compose |
| Testes | PHPUnit 11 |
| Debug | Xdebug |

---

## 3. Arquitetura

### 3.1 Diagrama de componentes

```mermaid
flowchart LR
    U(["Usuário (voz)"])

    subgraph EXT["Extensão Chrome (MV3)"]
        POP["popup.html / popup.js"]
        VOI["voice.html / voice.js<br/>Reconhecimento + TTS"]
        BG["background.js<br/>Service Worker"]
        TAB["Aba ativa<br/>(scripting.executeScript)"]
    end

    subgraph DOCKER["Docker Compose"]
        NGX["Nginx :8000"]
        PHP["Laravel (PHP-FPM)"]
        DB[("PostgreSQL")]
    end

    subgraph EXTERNAL["APIs externas"]
        GEM["Google Gemini"]
        BRV["Brave Search"]
        SB["Google Safe Browsing"]
    end

    U <--> VOI
    U --> POP
    POP <-->|runtime messages| BG
    VOI <-->|runtime messages| BG
    BG <-->|captura DOM / links| TAB
    BG -->|POST /api/process-dom| NGX
    NGX -->|FastCGI :9000| PHP
    PHP --> DB
    PHP --> SB
    PHP --> BRV
    PHP --> GEM
```

### 3.2 Responsabilidades

- **`voice.js`** — "ouvidos e boca": escuta, acumula a fala, detecta palavras-chave e lê as respostas.
- **`background.js`** — "cérebro local": roteia comandos, captura o DOM, mantém memória de links/fontes, navega e chama a API.
- **Backend** — "cérebro remoto": valida, sanitiza o HTML, orquestra busca + IA e devolve texto otimizado para voz.

### 3.3 Padrões no backend

`Route → Controller (invokable) → FormRequest → Action → Services`

- **FormRequest** valida a entrada (`StoreDomRequest`).
- **Action** (`ProcessDomAction`) concentra a regra de negócio.
- **Services** encapsulam integrações e transformações (Gemini, Brave, Safe Browsing, Sanitizer, LinkExtractor).
- **Rule customizada** (`SafeUrl`) integra o Safe Browsing à validação.

---

## 4. Estrutura de diretórios

```text
barrier-free/
├── docker-compose.yml          # Orquestra app, nginx e db
├── docker/
│   ├── nginx/default.conf      # Virtual host → PHP-FPM
│   └── php/                    # Dockerfile, php.ini, xdebug.ini
├── extensao/                   # Extensão Chrome (MV3)
│   ├── manifest.json
│   ├── background.js           # Service Worker (roteamento e orquestração)
│   ├── popup.html / popup.js   # UI da barra do navegador
│   ├── voice.html / voice.js   # Janela de voz (STT + TTS)
│   ├── content.js              # Script legado (não registrado no manifest)
│   └── icon.png
└── backend/                    # Laravel 12
    ├── app/
    │   ├── Actions/ProcessDomAction.php
    │   ├── Http/
    │   │   ├── Controllers/Api/DomProcessController.php
    │   │   └── Requests/StoreDomRequest.php
    │   ├── Rules/SafeUrl.php
    │   └── Services/
    │       ├── GeminiService.php
    │       ├── BraveSearchService.php
    │       ├── SafeBrowsingService.php
    │       ├── DomSanitizerService.php
    │       └── DomLinkExtractorService.php
    ├── routes/api.php          # POST /api/process-dom
    ├── config/services.php     # Chaves e modelos das APIs
    ├── config/cors.php
    └── tests/Unit/             # Testes do sanitizer e do extrator de links
```

---

## 5. Extensão Chrome (`extensao/`)

### 5.1 `manifest.json`

- **Manifest V3**, versão `1.0.0`.
- **Permissões:** `activeTab`, `scripting`, `storage`, `tabs`.
- **Host permissions:** `localhost`, `127.0.0.1` e `<all_urls>` (necessário para injetar script em qualquer site).
- **Service worker:** `background.js`.
- **Atalho:** `toggle-voice` → `Ctrl+Shift+U` para abrir/fechar a janela de voz.

### 5.2 `popup.html` / `popup.js`

Interface simples com dois botões:

- **📝 Resumir com IA** → envia `{action: 'summarizeDom'}` ao background.
- **🎤 Ativar/Desativar Voz** → alterna `isVoiceEnabled` (persistido em `chrome.storage.local`) e envia `{action: 'toggleVoice', enabled}`.

Também escuta `processResult` (exibe sucesso/erro) e `voiceStatus: ended` (reseta o botão quando a janela de voz é fechada).

### 5.3 `voice.html` / `voice.js` — janela de voz

Janela `popup` de 450×400 aberta pelo background. Tema escuro, botão de microfone animado com estados *ready / listening / processing*.

**Reconhecimento (STT)**
- `SpeechRecognition` com `lang = pt-BR`, `continuous = true`, `interimResults = false`.
- Inicia automaticamente ao abrir a janela; reinicia sozinho em `onend` enquanto estiver escutando.
- **Acumulador de fala (`accumulatedTranscript`)**: frases finais são concatenadas até aparecer uma palavra-chave (`COMMAND_KEYWORDS`). Assim o contexto dito antes do comando não se perde (ex.: "sobre o clima… buscar").
- Ao detectar comando válido: ativa `isProcessing`, para o reconhecimento (evita capturar a própria voz do TTS), toca o som de envio e manda `{action:'voiceCommand', command}` ao background.

**Síntese (TTS)**
- `SpeechSynthesisUtterance` em `pt-BR`; prioriza vozes com `Google`, `Online`, `Premium` ou `Natural` no nome.
- Ao terminar (`onend`, com 500 ms de folga) ou em erro, retoma a escuta.
- Mensagens de **erro também são faladas**, por acessibilidade.

**Feedback sonoro (Web Audio)**
- *Envio*: bip ascendente (520→880 Hz).
- *Recebimento*: arpejo descendente (Sol5 → Mi5 → Dó5).

### 5.4 `background.js` — Service Worker

**Estado em memória**

| Variável | Finalidade |
|---|---|
| `voiceWindowId` | Controla a janela de voz aberta |
| `lastSearchSources` | Fontes da última busca (para "abrir fonte 2") |
| `lastPageLinks` | Links extraídos da última página resumida/orientada |
| `lastAnalyzedTabId` | Aba analisada; navegar nela limpa `lastPageLinks` |

**Roteamento de `voiceCommand`** (ordem de avaliação):

1. `abrir / navegar / ir para / ir ao` → `handleNavigateVoiceCommand`
2. `comandos` → responde localmente com a lista de comandos (sem chamar API)
3. `resumir / resumo` → `captureCurrentTabDom('resumir', cmd)`
4. `orientar / ajudar / orientação / ajuda` → `captureCurrentTabDom('orientar', cmd)`
5. `buscar / pesquisar / procurar` → `handleSearchWithoutDom(cmd)`

**Captura do DOM** (`captureCurrentTabDom`)
- Pega a aba ativa de janela normal (ignora a janela de voz).
- Bloqueia páginas `chrome://` e `chrome-extension://` com mensagem falada.
- Injeta via `chrome.scripting.executeScript` uma função que clona `document.body`, remove `script, style, noscript, iframe` e retorna `{url, html_content}`.
- Anexa `command` e `user_prompt` e chama `handleSendDomRequest`.

**Navegação por voz** (`handleNavigateVoiceCommand`) — cascata de 5 estratégias:

1. **Fonte de busca**: se há `lastSearchSources` e o comando cita "fonte", "resultado" ou um número/ordinal → abre em **nova aba** (`detectSourceIndex` converte "primeira", "dois", "3"…).
2. **Links da última página analisada** (`lastPageLinks`) via `findBestPageLink`.
3. **Fallback ao vivo**: `findLinkInActiveTabDom` injeta um script na aba e coleta até 500 links visíveis (prioriza `main`, `nav`, `header`).
4. **Site conhecido / domínio explícito**: dicionário `KNOWN_SITES` (YouTube, Gmail, G1, UOL, Nubank…) ou regex de domínio (`.com`, `.com.br`, `.gov.br`…).
5. **Nenhum resultado**: fala uma mensagem de erro útil, sem abrir busca no Google.

**Algoritmo de matching (`findBestPageLink`)**

Normaliza (remove acentos, pontuação, *stop words*) e pontua cada link:

| Critério | Pontos |
|---|---|
| Palavra exata | +10 |
| Singular/plural | +9 |
| Match parcial (≥ 4 letras) | +5 |
| Todas as palavras do comando presentes | +20 |
| Mesmo nº de palavras que o link (match exato de seção) | +50 |
| Bônus de especificidade (links curtos) | `max(0, 10 − nº palavras)` |

Só aceita o melhor link se a pontuação for **≥ 10**.

**Comunicação com a API** (`handleSendDomRequest`)
- `POST http://localhost:8000/api/process-dom` com JSON.
- Guarda `search_sources` e `page_links` retornados.
- Emite `processResult` ao popup/voz com `ai_response`, `search_sources` e `message`.

**Eventos de ciclo de vida**
- `tabs.onUpdated`: invalida `lastPageLinks` quando a aba analisada muda de URL.
- `commands.onCommand`: atalho para alternar a janela de voz.
- `windows.onRemoved`: limpa estado e avisa o popup se a janela de voz for fechada manualmente.

---

## 6. Backend Laravel (`backend/`)

### 6.1 Rota

`routes/api.php` registra **uma única rota**, sem autenticação no MVP:

```php
Route::post('/process-dom', DomProcessController::class);
```

### 6.2 `StoreDomRequest` — validação

| Campo | Regras |
|---|---|
| `html_content` | `nullable`, `string` |
| `url` | `nullable`, `url`, `string`, **`SafeUrl`** |
| `command` | `nullable`, `string`, `in:resumir,orientar,buscar` |
| `user_prompt` | `nullable`, `string`, `max:255` |

`authorize()` retorna `true` (autenticação prevista para depois).

### 6.3 `SafeUrl` + `SafeBrowsingService`

Regra de validação que consulta o **Google Safe Browsing** (`MALWARE`, `SOCIAL_ENGINEERING`, `UNWANTED_SOFTWARE`, `POTENTIALLY_HARMFUL_APPLICATION`).

- Cache em arquivo por 1 hora (`safe_browsing:{md5(url)}`).
- Timeout agressivo (conexão 2 s / total 3 s) para não travar o fluxo por voz.
- **Fail-open**: sem chave, em timeout ou erro da API, a URL é liberada (com log de aviso).

### 6.4 `DomProcessController`

Controller invokable: loga URL, comando e tamanho do HTML, delega para `ProcessDomAction` e responde `{success, message, data}`.

### 6.5 `ProcessDomAction`

Regra de negócio central:

- Comandos de IA: `resumir`, `orientar`, `buscar` → chama `GeminiService::analyze`.
- Para `resumir` e `orientar` (com HTML e URL presentes) também executa `DomLinkExtractorService::extract` e devolve `page_links`.
- `buscar` não extrai links (não analisa a página atual).
- Qualquer outro comando retorna `status: unknown_command`.

### 6.6 `DomSanitizerService` — HTML → texto estruturado

Converte o HTML em um texto que preserva a **semântica de acessibilidade**, reduzindo ruído e tokens.

1. Carrega com `DOMDocument` (tolerante a HTML malformado, conversão UTF-8).
2. Remove `script, style, noscript, iframe, object, embed, applet, link, meta, head`.
3. Remove elementos ocultos (`aria-hidden="true"`, `hidden`, `display:none`, `visibility:hidden`).
4. Coloca o conteúdo `<main>` / `role="main"` **antes** do resto (cabeçalhos/menus não consomem o limite).
5. Converte cada nó em marcadores legíveis:

| HTML | Saída |
|---|---|
| `h1…h6` | `[Título N] texto` |
| `nav`, `main`, `header`, `footer`, `aside`, `section`, `article` | `[Navegação]…[/Navegação]` etc. |
| `a` | `[Link: texto]` (URL **omitida** de propósito) |
| `img` | `[Imagem: alt]` (decorativas são omitidas) |
| `button` | `[Botão: rótulo]` |
| `input/select/textarea` | `[Campo e-mail: …]`, `[Campo seleção: … (opções: …)]` (hidden ignorado) |
| `form` | `[Formulário (nome)]…[/Formulário]` |
| `table` | `[Tabela: …]` com colunas separadas por `|` (até 50 linhas) |
| `svg` | `[Ícone: …]` (só texto acessível) |
| `ul/ol/li` | `• item` |
| `role` ARIA | Traduzido para PT-BR (`[Busca]`, `[Diálogo]`…) |

6. Normaliza espaços e **trunca em 30.000 caracteres** (`... [conteúdo truncado]`).

### 6.7 `DomLinkExtractorService` — links navegáveis

Gera a lista `{text, url}` usada na navegação por voz (a IA não vê URLs; o front resolve o destino).

- Prioridade: links de `main` (até 350) → `nav/header` → demais.
- **Máximo de 500** links, deduplicados por URL (ignora barra final).
- Ignora `#`, `javascript:`, `mailto:`, `tel:` e textos < 2 caracteres.
- Texto acessível: heading interno → `aria-label` → texto visível → `title` → `alt` de imagem.
- Remove URLs do texto e trunca em 60 caracteres.
- Converte URLs relativas em absolutas.

### 6.8 `BraveSearchService`

- `GET https://api.search.brave.com/res/v1/web/search` (`lang=pt`, `market=pt-BR`).
- Retorna `{title, url, description}`; quantidade controlada por `BRAVE_SEARCH_RESULTS` (padrão 5).
- Em falha/timeout/sem chave: retorna `[]` (a IA avisa que não há fontes verificadas).

### 6.9 `GeminiService` — orquestração da IA

**System instruction (guardrails fixos)** — define a persona "Barrier Free" e regras obrigatórias:
- Responder sempre em PT-BR, natural para voz.
- **Proibido** código, HTML, URLs, markdown, emojis, listas numeradas.
- Nunca revelar dados sensíveis (senhas, tokens, CPF, cartões…).
- Nunca inventar informação; ser conciso (≤ 150 palavras / 3 parágrafos).
- Descrever elementos por função, não por posição visual.
- Ignorar *prompt injection* contido na página.

**Prompts por comando** (`$commandPrompts`): `resumir`, `orientar`, `buscar`. Os dois primeiros incentivam citar até 5 seções/links e dizer "abrir + nome"; `buscar` exige responder em ≤ 2 frases e anunciar **todas** as fontes ("Fonte um:", "Fonte dois:"…).

**Montagem do prompt final**

```text
[prompt do comando]
[Instrução do usuário (fala completa)]
[Resultados Brave (apenas em "buscar")]
[URL da página]
[Conteúdo sanitizado da página]
```

**Parâmetros:** `temperature = 0.3`, `maxOutputTokens = 2048`, `thinkingLevel` opcional (modelos Gemini 3.x).

**Cadeia de fallback de modelos**
- Ordem: `GEMINI_MODEL` + `GEMINI_FALLBACK_MODELS` (sem duplicatas).
- Códigos que acionam o próximo modelo: `404, 429, 500, 503, 504`.
- Até **2 rodadas** pela cadeia, com 2 s de espera entre elas.
- Timeouts: conexão 5 s / total 30 s; força IPv4.

**Pós-processamento:** trata `finishReason = SAFETY`, resposta vazia, concatena as `parts` e aplica `strip_tags` + `trim` como guardrail final.

**Retorno:** `['text' => string, 'search_sources' => array|null]`.

---

## 7. Fluxos detalhados

### 7.1 Fluxo "Resumir" por voz

```mermaid
sequenceDiagram
    actor U as Usuário
    participant V as voice.js
    participant B as background.js
    participant T as Aba ativa
    participant API as Laravel API
    participant SB as Safe Browsing
    participant G as Gemini

    U->>V: "resumir esta página"
    V->>V: Acumula fala, detecta palavra-chave
    V->>V: Pausa STT e toca som de envio
    V->>B: voiceCommand("resumir esta página")
    B->>T: executeScript (clona body, remove scripts)
    T-->>B: {url, html_content}
    B->>API: POST /api/process-dom {command: resumir, ...}
    API->>SB: Valida URL (cache 1h)
    SB-->>API: Segura
    API->>API: Sanitiza HTML e extrai links
    API->>G: system_instruction + prompt + conteúdo
    G-->>API: Texto da resposta
    API-->>B: {ai_response, page_links}
    B->>B: Guarda pageLinks (chrome.storage.session)
    B-->>V: processResult(ai_response)
    V->>U: Fala a resposta (TTS)
    V->>V: Retoma a escuta
```

### 7.2 Fluxo "Buscar" por voz

Vocabulário único para o usuário: **"opção"** ("fonte" e "resultado" continuam aceitos como sinônimos nos comandos). A fala segue sempre a mesma estrutura, montada por código (`SearchSpeechFormatter`), e **não** pelo Gemini:

1. Resposta curta do Gemini (máx. 2 frases, sem citar sites).
2. "Encontrei N opções."
3. "Opção 1: <site>, <título curto>." … até a opção N.
4. "Para abrir, diga abrir opção seguido do número, de 1 a N. Para ouvir de novo, diga repetir opções."

```mermaid
sequenceDiagram
    actor U as Usuário
    participant V as voice.js
    participant B as background.js
    participant API as Laravel API
    participant BR as Brave Search
    participant G as Gemini

    U->>V: "buscar notícias sobre energia solar"
    V->>B: voiceCommand
    B->>API: POST {command: buscar, user_prompt, html vazio}
    API->>BR: Pesquisa (pt-BR, 5 resultados)
    BR-->>API: títulos, urls, descrições
    API->>G: Pergunta + trechos (sem pedir lista)
    G-->>API: Resposta curta
    API->>API: SearchSpeechFormatter anexa lista de opções
    API-->>B: {ai_response, search_sources}
    B->>B: Guarda searchSources (chrome.storage.session)
    B-->>V: processResult
    V->>U: Fala resposta + opções (tela mostra o mesmo texto)
    U->>V: "abrir opção dois"
    V->>B: voiceCommand
    B->>B: parseOptionNumber → índice 1
    B->>U: Abre a opção em nova aba
```

> **Por que `chrome.storage.session`?** Em Manifest V3 o service worker é encerrado após ~30 s ocioso, e a leitura em voz da resposta dura mais que isso. Variáveis globais perdiam a lista de opções, fazendo "abrir fonte 4" cair no fluxo genérico de links da página. O estado (`searchSources`, `pageLinks`, `analyzedTabId`, `analyzedUrl`) agora é persistido.

### 7.3 Fluxo de navegação ("abrir …")

```mermaid
flowchart TD
    A["Comando contém abrir / navegar / ir para"] --> B{"Há opções de busca salvas?"}
    B -- Sim --> B0{"Cita número / último?"}
    B0 -- Sim --> B00{"Número dentro da faixa?"}
    B00 -- Sim --> B1["Abre opção N em nova aba"]
    B00 -- Não --> B2["Fala: 'Só há N opções'"]
    B0 -- Não --> B3{"Só 'abrir'?"}
    B3 -- Sim --> B4["Repete a lista de opções"]
    B3 -- Não --> B5{"Cita o site de uma opção?<br/>(ex.: abrir UOL)"}
    B5 -- Sim --> B1
    B5 -- Não --> C
    B -- Não --> C{"Há pageLinks<br/>com bom match?"}
    C -- Sim --> C1["Navega na mesma aba<br/>e limpa a memória"]
    C -- Não --> D{"Link encontrado<br/>no DOM ao vivo?"}
    D -- Sim --> D1["Navega na mesma aba"]
    D -- Não --> E{"Site conhecido<br/>ou domínio falado?"}
    E -- Sim --> E1["Abre URL"]
    E -- Não --> F["Fala: 'Não encontrei a opção X'"]
```

O comando **"repetir opções"** relê a lista da última busca (ou avisa que não há opções).

Testes da lógica de opções: `node --test extensao/tests/option-parsing.test.mjs`.

### 7.4 Fluxo "Resumir com IA" pelo popup

`popup.js` → `summarizeDom` → `captureCurrentTabDom('resumir')` → mesma cadeia do fluxo 7.1. O resultado aparece como texto no popup (sem TTS, pois a leitura é feita pela janela de voz quando ativa).

### 7.5 Fluxo "Comandos"

Resolvido 100% na extensão: `background.js` monta o texto de ajuda e emite `processResult` com `ai_response`; a janela de voz o lê em voz alta. Nenhuma chamada de rede.

### 7.6 Máquina de estados da janela de voz

```mermaid
stateDiagram-v2
    [*] --> Ouvindo: abre janela / startListening
    Ouvindo --> Ouvindo: fala sem palavra-chave (acumula)
    Ouvindo --> Processando: palavra-chave detectada
    Processando --> Falando: processResult com ai_response
    Processando --> Ouvindo: processResult sem ai_response
    Falando --> Ouvindo: TTS terminou (+500ms)
    Ouvindo --> Parado: clique no microfone
    Parado --> Ouvindo: clique no microfone
```

---

## 8. Contrato da API

### `POST /api/process-dom`

**Headers:** `Content-Type: application/json`, `Accept: application/json`

**Request**

```json
{
  "url": "https://g1.globo.com/",
  "html_content": "<div>...</div>",
  "command": "resumir",
  "user_prompt": "resumir esta página"
}
```

**Response 200**

```json
{
  "success": true,
  "message": "DOM recebido e processado com sucesso.",
  "data": {
    "analyzed_url": "https://g1.globo.com/",
    "command": "resumir",
    "status": "processed",
    "ai_response": "Esta página é a home do portal G1...",
    "search_sources": null,
    "page_links": [
      { "text": "Economia", "url": "https://g1.globo.com/economia/" }
    ]
  }
}
```

**Response (`buscar`)** — `search_sources` vem preenchido e `page_links` é `null`. `ai_response` já contém a resposta curta + a lista falada de opções:

```json
"search_sources": [
  {
    "number": 4,
    "title": "UOL Economia: encontre orientações e notícias sobre economia",
    "url": "https://economia.uol.com.br/",
    "site_name": "UOL",
    "spoken_title": "UOL Economia"
  }
]
```

**Erros:** `422` (validação, incluindo URL insegura), `5xx` (falha interna). A extensão trata `!response.ok` e fala a mensagem de erro.

---

## 9. Infraestrutura Docker

[`docker-compose.yml`](docker-compose.yml) define 3 serviços na rede `barrier_network`:

| Serviço | Imagem | Porta | Função |
|---|---|---|---|
| `app` (`laravel_app`) | build `docker/php/Dockerfile` (php:8.2-fpm) | 9000 (interna) | Executa o Laravel; monta `./backend` em `/var/www` |
| `nginx` (`laravel_nginx`) | `nginx:alpine` | **8000 → 80** | Serve `public/` e repassa `.php` ao FPM (`app:9000`) |
| `db` (`laravel_db`) | `postgres:15-alpine` | 5432 | Banco; volume persistente `dbdata` |

**Dockerfile PHP:** instala `git`, `curl`, `libpng`, `libonig`, `libxml2`, `libpq`, `zip`; extensões `pdo`, `pdo_pgsql`, `pgsql`, `mbstring`, `exif`, `pcntl`, `bcmath`, `gd`; **Xdebug**; **Composer**.

**`php.ini` customizado:** `post_max_size=100M`, `upload_max_filesize=100M`, `memory_limit=256M` (importante porque o DOM de páginas grandes é enviado no corpo do POST). O Nginx também usa `client_max_body_size 100M`.

`extra_hosts: host.docker.internal:host-gateway` permite ao contêiner alcançar o host (útil para Xdebug).

---

## 10. Configuração e variáveis de ambiente

Arquivo `backend/.env` (modelo em `.env.example`). Mapeado em `config/services.php`.

| Variável | Descrição | Padrão |
|---|---|---|
| `GEMINI_API_KEY` | Chave da API Gemini | — |
| `GEMINI_MODEL` | Modelo principal | `gemini-2.0-flash` |
| `GEMINI_FALLBACK_MODELS` | Modelos alternativos (separados por vírgula) | vazio |
| `GEMINI_THINKING_LEVEL` | `MINIMAL/LOW/MEDIUM/HIGH` (Gemini 3.x) | vazio |
| `BRAVE_SEARCH_API_KEY` | Chave Brave Search | — |
| `BRAVE_SEARCH_RESULTS` | Nº de resultados | `5` |
| `GOOGLE_SAFE_BROWSING_API_KEY` | Chave Safe Browsing | — |
| `DB_CONNECTION/HOST/PORT/DATABASE/USERNAME/PASSWORD` | PostgreSQL | `pgsql / db / 5432 / barrier_free / postgres / postgres` |

> [!WARNING]
> Nunca versione o `.env` com chaves reais. Confirme que ele está no `.gitignore`.

---

## 11. Segurança e guardrails

| Camada | Medida |
|---|---|
| Entrada | Validação de campos, limite de 255 caracteres no `user_prompt`, `command` restrito a uma whitelist |
| URL | Verificação no Google Safe Browsing antes de qualquer processamento |
| HTML | Remoção de scripts/iframes/objetos e elementos ocultos antes de ir à IA |
| Conteúdo | Truncamento em 30.000 caracteres; URLs omitidas do texto enviado à IA |
| IA (entrada) | System instruction contra vazamento de dados sensíveis e *prompt injection* |
| IA (saída) | Proibição de formatação visual + `strip_tags` na resposta |
| Rede | Timeouts em todas as chamadas externas |
| CORS | Liberado para `api/*` (`allowed_origins: *`) — adequado ao MVP |
| Chaves | Mantidas só no backend; a extensão nunca as vê |

---

## 12. Resiliência e tratamento de falhas

| Cenário | Comportamento |
|---|---|
| Gemini sobrecarregado (429/503/504…) | Troca para o próximo modelo da cadeia; 2 rodadas com *backoff* |
| Timeout do Gemini | Mensagem falada: "A análise demorou muito…" |
| Resposta bloqueada (`SAFETY`) | Mensagem amigável de bloqueio |
| Resposta vazia | Mensagem de falha na geração |
| Brave indisponível | Segue sem fontes; a IA avisa que não há fontes verificadas |
| Safe Browsing offline/sem chave | Libera a URL (*fail-open*) e registra aviso |
| Página interna do Chrome | Mensagem falada orientando a navegar para um site |
| Falha de injeção de script | Mensagem "Não é possível ler esta aba" |
| Erro de rede na extensão | `processResult` com `success:false`, lido em voz alta |
| Link não encontrado | Mensagem específica, sem sair da página |

---

## 13. Testes

Executados com PHPUnit (`composer test`).

- **`DomSanitizerServiceTest`** — ~30 casos: remoção de tags, links, imagens, formulários, headings, landmarks/ARIA, listas, tabelas, SVG, elementos ocultos, truncamento, HTML malformado e página realista.
- **`DomLinkExtractorServiceTest`** — prioridade de `nav/header` sobre `main`, heading interno, `aria-label`, elementos ocultos e deduplicação.

> [!NOTE]
> Não há testes automatizados para `GeminiService`, `BraveSearchService`, `SafeBrowsingService`, controller/rota nem para a extensão.

---

## 14. Como executar

### Backend

```bash
# 1. Configurar variáveis
cp backend/.env.example backend/.env   # preencha as chaves de API

# 2. Subir os contêineres
docker compose up -d --build

# 3. Dependências e chave da aplicação
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate

# 4. Rodar os testes
docker compose exec app php artisan test
```

API disponível em `http://localhost:8000/api/process-dom`.

### Extensão

1. Abra `chrome://extensions` e ative o **Modo do desenvolvedor**.
2. Clique em **Carregar sem compactação** e selecione a pasta `extensao/`.
3. Clique no ícone da extensão → **Ativar Voz** (ou use `Ctrl+Shift+U`) e permita o microfone.
4. Em um site normal, diga: *"resumir"*, *"orientar"*, *"buscar …"*, *"abrir …"* ou *"comandos"*.

---

## 15. Pontos de atenção e dívidas técnicas

1. **Testes desatualizados no sanitizer:** `DomSanitizerServiceTest` ainda espera `[Link: texto -> url]` e limite de ~15.000 caracteres, mas o código atual omite URLs e usa 30.000. Provavelmente alguns testes falham.
2. **`content.js` sem uso:** não está declarado no `manifest.json` (a captura é feita via `executeScript` no background).
3. **Textos desatualizados na UI/código:** o comentário em `background.js` cita `Alt+Shift+B`, mas o atalho real é `Ctrl+Shift+U`; o `voice.html` exibe "Diga enviar ou analisar…" e a dica lista só *resumir/orientar/buscar*.
4. **API sem autenticação:** Sanctum está instalado e há migração de tokens, mas a rota é pública (`authorize()` retorna `true`). Antes de produção, proteger a rota e restringir o CORS.
5. **Chave Gemini na query string:** `?key=` pode vazar em logs de proxy; considerar o header `x-goog-api-key`.
6. **URL da API fixa em `localhost:8000`:** extrair para configuração (ex.: `chrome.storage`) para diferentes ambientes.
7. **`SafeBrowsingService` *fail-open*** e `SafeUrl` só valida a URL, não os links internos da página.
8. **Banco sem uso funcional:** PostgreSQL, migrations de `users/cache/jobs` existem, mas a aplicação não persiste dados. O driver de cache padrão é `database`, porém o Safe Browsing usa o store `file`.
9. **Processamento síncrono:** chamadas à IA bloqueiam a requisição (até ~30 s × modelos × 2 rodadas). Considerar fila (`jobs`) ou *streaming*.
10. **Privacidade:** o conteúdo integral da página (inclusive logada) é enviado ao Gemini. Avaliar filtros para páginas sensíveis (bancos, e-mails) e política de privacidade.
11. **Logs:** `DomProcessController` e `GeminiService` registram URLs, queries e, em erros, corpos de resposta — revisar para produção.
12. **Reconhecimento de comando por `includes`:** palavras como "ajuda" ou "abrir" em frases comuns podem disparar comandos sem querer.
13. **`README.md` da raiz** está praticamente vazio; esta documentação pode ser referenciada nele.
