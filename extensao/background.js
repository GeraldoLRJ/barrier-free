let voiceWindowId = null;

// Envia mensagem para a janela de voz/popup sem gerar erro quando ninguém está escutando
// (ex.: janela de voz fechada). Sem o catch, o Chrome registra "Could not establish connection.
// Receiving end does not exist." como erro não tratado da extensão.
function safeSendMessage(message) {
    try {
        const result = chrome.runtime.sendMessage(message);
        if (result && typeof result.catch === 'function') {
            result.catch((e) => {
                const msg = (e && e.message) || '';
                if (!/Receiving end does not exist|message port closed/i.test(msg)) {
                    console.warn('Falha ao enviar mensagem:', msg);
                }
            });
        }
    } catch (e) {
        console.warn('Falha ao enviar mensagem:', e && e.message);
    }
}

// --- Estado da navegação por voz ---
// Em Manifest V3 o service worker é encerrado após ~30s ocioso (a leitura em voz da resposta dura mais que isso),
// então variáveis globais se perdem. Por isso o estado fica em chrome.storage.session:
//   searchSources: opções da última busca (número, site_name, spoken_title, title, url)
//   pageLinks:     links extraídos da última página resumida/orientada
//   analyzedTabId / analyzedUrl: aba analisada (para invalidar ao trocar de URL)
const STATE_KEYS = ['searchSources', 'pageLinks', 'analyzedTabId', 'analyzedUrl'];

async function getState() {
    return await chrome.storage.session.get(STATE_KEYS);
}

async function setState(patch) {
    await chrome.storage.session.set(patch);
}

async function clearState(keys) {
    await chrome.storage.session.remove(keys);
}

function normalizeSpeech(text) {
    return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

// Mapeia palavras ordinais/cardinais (sem acento) para o número da opção (1-based)
const NUMBER_WORDS = {
    'um': 1, 'uma': 1, 'primeiro': 1, 'primeira': 1,
    'dois': 2, 'duas': 2, 'segundo': 2, 'segunda': 2,
    'tres': 3, 'terceiro': 3, 'terceira': 3,
    'quatro': 4, 'quarto': 4, 'quarta': 4,
    'cinco': 5, 'quinto': 5, 'quinta': 5,
    'seis': 6, 'sexto': 6, 'sexta': 6,
    'sete': 7, 'setimo': 7, 'setima': 7,
    'oito': 8, 'oitavo': 8, 'oitava': 8,
    'nove': 9, 'nono': 9, 'nona': 9,
    'dez': 10, 'decimo': 10, 'decima': 10,
};

// Vocabulário único para o usuário é "opção"; "fonte" e "resultado" continuam aceitos como sinônimos.
const OPTION_KEYWORDS_REGEX = /\b(opcao|opcoes|fonte|fontes|resultado|resultados|numero)\b/;
const OPTION_FILLER_REGEX = /\b(quero|gostaria|queria|favor|por|abrir|navegar|para|ir|ao|a|o|os|as|de|do|da|me|leve|opcao|opcoes|fonte|fontes|resultado|resultados|numero)\b/g;
const BARE_NUMBER_REGEX = new RegExp(`^(\\d{1,2}|ultimo|ultima|${Object.keys(NUMBER_WORDS).join('|')})$`);

// Palavras ignoradas ao comparar o nome falado com o site de uma opção
const SITE_NAME_STOP_WORDS = new Set([
    'site', 'pagina', 'link', 'portal', 'jornal', 'canal', 'de', 'do', 'da', 'dos', 'das',
    'e', 'em', 'no', 'na', 'um', 'uma', 'me', 'leve', 'opcao', 'fonte', 'resultado',
]);

// Retorna o número falado (1-based), 'last' para "último/última" ou null se não houver número
function extractOptionNumber(cmd) {
    const normalized = normalizeSpeech(cmd);
    if (/\b(ultimo|ultima)\b/.test(normalized)) return 'last';

    const digits = normalized.match(/\b(\d{1,2})\b/);
    if (digits) return parseInt(digits[1], 10);

    for (const [word, value] of Object.entries(NUMBER_WORDS)) {
        if (new RegExp(`\\b${word}\\b`).test(normalized)) return value;
    }
    return null;
}

// Resolve o número falado para um índice válido; NUNCA escolhe outra opção em silêncio quando fora da faixa
function parseOptionNumber(cmd, total) {
    const spoken = extractOptionNumber(cmd);
    if (spoken === 'last') return { index: total - 1, number: total };
    if (spoken === null || spoken < 1 || spoken > total) return { outOfRange: spoken };
    return { index: spoken - 1, number: spoken };
}

function cleanOptionCommand(cmd) {
    return normalizeSpeech(cmd)
        .replace(OPTION_FILLER_REGEX, ' ')
        .replace(/[^\w\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

// "abrir opção 4", "abrir fonte quatro", "abrir a quarta", "abrir 4", "abrir o último"
function isOptionSelectionCommand(cmd) {
    if (extractOptionNumber(cmd) === null) return false;
    if (OPTION_KEYWORDS_REGEX.test(normalizeSpeech(cmd))) return true;
    return BARE_NUMBER_REGEX.test(cleanOptionCommand(cmd));
}

// "abrir" / "abrir opção" sem nome nem número
function isBareOpenCommand(cmd) {
    return cleanOptionCommand(cmd) === '';
}

// "Site, título curto" (ou só o título quando ele já contém o nome do site) — espelha o backend
function describeSource(source) {
    const site = (source.site_name || '').trim();
    const title = (source.spoken_title || source.title || '').trim();
    if (!title) return site || 'sem título';
    if (!site || normalizeSpeech(title).includes(normalizeSpeech(site))) return title;
    return `${site}, ${title}`;
}

function buildOptionsSpeech(sources) {
    const total = sources.length;
    const lines = [total === 1 ? 'Você tem 1 opção.' : `Você tem ${total} opções.`];
    sources.forEach((source, i) => {
        lines.push(`Opção ${i + 1}: ${describeSource(source)}.`);
    });
    lines.push(total === 1
        ? 'Para abrir, diga abrir opção 1.'
        : `Para abrir, diga abrir opção seguido do número, de 1 a ${total}.`);
    return lines.join('\n');
}

// Envia uma mensagem falada/exibida na janela de voz
function announce(message, success = true) {
    safeSendMessage({
        action: "processResult",
        success: success,
        message: message,
        ai_response: success ? message : null
    });
}

async function openSearchOption(source, number) {
    await navigateToUrl(
        source.url,
        true,
        "Navegando para a opção " + number,
        "Abrindo a opção " + number + ": " + describeSource(source)
    );
}

// Abrir por nome: só considera opções cujo SITE foi citado (ex.: "abrir UOL"), para que um pedido como
// "abrir notícias" dentro de outra página não seja sequestrado por um título de resultado antigo.
function matchSourceBySite(cmd, sources) {
    const spokenWords = extractTargetClause(cmd)
        .replace(/[^\w\s]/g, ' ')
        .split(/\s+/)
        .filter(w => w.length >= 2 && !SITE_NAME_STOP_WORDS.has(w));
    if (spokenWords.length === 0) return null;

    const ignoredLabels = new Set(['www', 'com', 'br', 'org', 'net', 'gov', 'edu', 'io', 'app', 'co', 'm', 'web']);
    const candidates = [];

    sources.forEach((source, i) => {
        let hostTokens = [];
        try {
            hostTokens = new URL(source.url).hostname.toLowerCase().split(/[.\-]/).filter(t => t.length >= 2 && !ignoredLabels.has(t));
        } catch { }
        const siteTokens = new Set([
            ...normalizeSpeech(source.site_name || '').replace(/[^\w\s]/g, ' ').split(/\s+/).filter(t => t.length >= 2),
            ...hostTokens
        ]);
        if (spokenWords.some(w => siteTokens.has(w))) {
            candidates.push({ text: `${source.site_name || ''} ${source.title || ''}`.trim(), url: source.url, source, number: i + 1 });
        }
    });

    if (candidates.length === 0) return null;
    if (candidates.length === 1) return candidates[0];
    return findBestPageLink(cmd, candidates, null) || candidates[0];
}

async function navigateToUrl(url, asNewTab = false, successMessage = null, aiResponse = null) {
    let tabs = await chrome.tabs.query({ active: true, windowType: 'normal' });
    if (tabs.length > 0) {
        let tab = tabs[0];
        if (asNewTab) {
            await chrome.tabs.create({ url: url, windowId: tab.windowId });
        } else {
            await chrome.tabs.update(tab.id, { url: url });
        }
        await chrome.windows.update(tab.windowId, { focused: true });
    } else {
        await chrome.windows.create({ url: url, type: 'normal' });
    }

    if (successMessage) {
        safeSendMessage({
            action: "processResult",
            success: true,
            message: successMessage,
            ai_response: aiResponse
        });
    }
}

async function captureCurrentTabDom(command = 'analisar', userPrompt = null) {
    // Pegar a aba ativa da janela normal do navegador, ignorando o popup de voz
    let [tab] = await chrome.tabs.query({ active: true, windowType: 'normal' });

    // Salvar o tabId da aba que está sendo analisada para detectar navegação posterior
    if (tab) {
        await setState({ analyzedTabId: tab.id, analyzedUrl: tab.url || null });
    }

    if (tab) {
        // Verifica se a aba não é uma página restrita do próprio Chrome
        if (tab.url && (tab.url.startsWith("chrome://") || tab.url.startsWith("chrome-extension://"))) {
            console.error("Tentativa de injetar em página restrita do Chrome");
            safeSendMessage({
                action: "processResult",
                success: false,
                message: `O comando "${command}" não funciona em páginas internas do Chrome. Navegue para um site e tente novamente. Você também pode dizer "buscar" ou "navegar para" em qualquer momento.`
            });
            return;
        }

        // Execute script in the active tab to get the DOM
        chrome.scripting.executeScript({
            target: { tabId: tab.id },
            function: () => {
                const clone = document.body.cloneNode(true);

                // cloneNode copia só atributos HTML: valores digitados/alterados via JS e a opção
                // selecionada se perdem. Sincroniza o estado vivo dos campos no clone (mesma ordem).
                const liveFields = document.body.querySelectorAll('input, textarea, select');
                const cloneFields = clone.querySelectorAll('input, textarea, select');
                liveFields.forEach((live, i) => {
                    const copy = cloneFields[i];
                    if (!copy) return;
                    const tag = live.tagName.toLowerCase();
                    const auto = (live.getAttribute('autocomplete') || '').toLowerCase();
                    if (auto.startsWith('cc-') || auto === 'one-time-code') return;

                    if (tag === 'input') {
                        const type = (live.type || 'text').toLowerCase();
                        if (['password', 'hidden', 'file'].includes(type)) return;
                        if (type === 'checkbox' || type === 'radio') {
                            if (live.checked) copy.setAttribute('checked', '');
                            else copy.removeAttribute('checked');
                        } else {
                            copy.setAttribute('value', live.value);
                        }
                    } else if (tag === 'textarea') {
                        copy.textContent = live.value;
                    } else if (tag === 'select') {
                        Array.from(copy.options).forEach((opt, j) => {
                            if (live.options[j] && live.options[j].selected) opt.setAttribute('selected', '');
                            else opt.removeAttribute('selected');
                        });
                    }
                });

                clone.querySelectorAll('script, style, noscript, iframe').forEach(el => el.remove());
                return {
                    url: window.location.href,
                    html_content: clone.innerHTML
                };
            }

        }, (injectionResults) => {
            if (chrome.runtime.lastError) {
                console.error("Erro de injeção:", chrome.runtime.lastError.message);
                safeSendMessage({
                    action: "processResult",
                    success: false,
                    message: "Não é possível ler esta aba. Tente em um site normal (ex: google.com)."
                });
                return;
            }
            if (injectionResults && injectionResults[0] && injectionResults[0].result) {
                // Adicionar o comando e o prompt do usuário ao payload antes de enviar
                const data = injectionResults[0].result;
                data.command = command;

                // Se foi passado um texto completo falado pelo usuário, envia junto
                if (userPrompt) {
                    data.user_prompt = userPrompt;
                }

                handleSendDomRequest({ data });
            }
        });
    }
}

// --- Função de busca sem DOM (funciona em qualquer página, inclusive chrome://) ---
function handleSearchWithoutDom(userPrompt) {
    handleSendDomRequest({
        data: {
            url: '',
            html_content: '',
            command: 'buscar',
            user_prompt: userPrompt
        }
    });
}

// --- Extrai a parte alvo do comando falado (priorizando o que vem após a última palavra de comando "abrir"/"navegar") ---
function extractTargetClause(cmd) {
    const normalized = cmd
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();

    const triggerRegex = /\b(navegar\s+para|ir\s+para|ir\s+ao|abrir|navegar)\b/gi;
    let match;
    let lastMatch = null;
    while ((match = triggerRegex.exec(normalized)) !== null) {
        lastMatch = match;
    }

    if (!lastMatch) {
        return normalized;
    }

    const afterTrigger = normalized.slice(lastMatch.index + lastMatch[0].length).trim();
    const beforeTrigger = normalized.slice(0, lastMatch.index).trim();

    // Verifica se há palavras úteis após o gatilho (ex: "abrir notícias" ou "notícias abrir notícias")
    const stopWords = new Set([
        'quero', 'gostaria', 'queria', 'favor', 'por', 'abrir', 'navegar',
        'para', 'ir', 'ao', 'a', 'o', 'os', 'as', 'e', 'site', 'pagina',
        'link', 'de', 'do', 'da', 'dos', 'das', 'um', 'uma', 'em', 'no', 'na',
        'me', 'leve', 'opcao', 'secao', 'sessao', 'menu', 'aba', 'area',
        'portal', 'jornal', 'canal'
    ]);

    const afterWords = afterTrigger
        .replace(/[^\w\s]/g, ' ')
        .split(/\s+/)
        .filter(w => w.length > 1 && !stopWords.has(w));

    if (afterWords.length > 0) {
        return afterTrigger;
    }

    return beforeTrigger;
}

// --- Extrai uma URL navegável a partir do texto falado pelo usuário (apenas sites conhecidos ou domínios explícitos) ---
function extractUrlFromSpeech(cmd) {
    // Sites mais comuns mapeados por nome coloquial
    const KNOWN_SITES = {
        'youtube': 'https://www.youtube.com',
        'google': 'https://www.google.com.br',
        'gmail': 'https://mail.google.com',
        'facebook': 'https://www.facebook.com',
        'instagram': 'https://www.instagram.com',
        'twitter': 'https://www.twitter.com',
        'x': 'https://www.x.com',
        'whatsapp': 'https://web.whatsapp.com',
        'amazon': 'https://www.amazon.com.br',
        'mercado livre': 'https://www.mercadolivre.com.br',
        'mercadolivre': 'https://www.mercadolivre.com.br',
        'wikipedia': 'https://pt.wikipedia.org',
        'netflix': 'https://www.netflix.com',
        'spotify': 'https://open.spotify.com',
        'linkedin': 'https://www.linkedin.com',
        'github': 'https://www.github.com',
        'globo': 'https://www.globo.com',
        'g1': 'https://g1.globo.com',
        'uol economia': 'https://economia.uol.com.br',
        'uol': 'https://www.uol.com.br',
        'terra': 'https://www.terra.com.br',
        'nubank': 'https://www.nubank.com.br',
        'wise': 'https://wise.com/br',
        'banco central do brasil': 'https://www.bcb.gov.br',
        'banco central': 'https://www.bcb.gov.br',
    };

    const targetClause = extractTargetClause(cmd);

    // Detecta se o usuário falou um domínio diretamente (ex: "abrir globo.com")
    const domainMatch = targetClause.match(/([a-z0-9\-]+\.(com|com\.br|org|net|gov\.br|edu\.br|br|io|app)(\.[a-z]{2})?)/i);
    if (domainMatch) {
        return { url: 'https://' + domainMatch[0], spokenMessage: 'Abrindo ' + domainMatch[0] };
    }

    // Verifica se algum site conhecido foi mencionado (ordenado do nome mais específico/longo para o mais curto)
    const sortedSites = Object.entries(KNOWN_SITES).sort((a, b) => b[0].length - a[0].length);
    for (const [name, url] of sortedSites) {
        const nameNorm = name.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        const escaped = nameNorm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp(`\\b${escaped}\\b`, 'i');
        if (regex.test(targetClause)) {
            return { url: url, spokenMessage: 'Abrindo o site ' + name };
        }
    }

    return null;
}

// --- Extrai o nome limpo da opção que o usuário tentou abrir ---
function extractSpokenTargetName(cmd) {
    const stopWords = new Set([
        'quero', 'gostaria', 'queria', 'favor', 'por', 'abrir', 'navegar',
        'para', 'ir', 'ao', 'a', 'o', 'os', 'as', 'um', 'uma', 'de', 'do', 'da',
        'dos', 'das', 'em', 'no', 'na', 'site', 'pagina', 'página', 'link',
        'opcao', 'opção', 'secao', 'seção', 'sessao', 'sessão', 'menu', 'aba', 'area', 'área'
    ]);
    const targetClause = extractTargetClause(cmd);
    const words = targetClause
        .replace(/[^\p{L}\p{N}\s]/gu, ' ')
        .split(/\s+/)
        .filter(w => w.length > 1 && !stopWords.has(w));
    return [...new Set(words)].join(' ');
}

// --- Busca direta nos links visíveis da aba ativa caso lastPageLinks não contenha o link ---
async function findLinkInActiveTabDom(cmd) {
    try {
        const [tab] = await chrome.tabs.query({ active: true, windowType: 'normal' });
        if (!tab || !tab.url || tab.url.startsWith('chrome://') || tab.url.startsWith('chrome-extension://')) {
            return null;
        }

        const results = await chrome.scripting.executeScript({
            target: { tabId: tab.id },
            func: () => {
                const seen = new Set();
                const externalLinks = [];
                const internalLinks = [];
                const isSearchPage = (location.hostname.includes('google.') || location.hostname.includes('bing.com')) && location.pathname.startsWith('/search');
                const blockedSearchTexts = new Set([
                    'modo ia', 'ai mode', 'fazer login', 'ir para o conteúdo principal',
                    'ajuda sobre acessibilidade', 'feedback', 'enviar feedback',
                    'privacidade', 'termos', 'configurações', 'ferramentas', 'mais', 'tudo'
                ]);

                const containers = [
                    ...document.querySelectorAll('#search, #rso, main, [role="main"], nav, [role="navigation"], header'),
                    document.body
                ];

                for (const container of containers) {
                    if (!container) continue;
                    const anchors = container.querySelectorAll('a[href]');
                    for (const a of anchors) {
                        const rawHref = (a.getAttribute('href') || '').trim();
                        if (!rawHref || rawHref === '#' || rawHref.startsWith('javascript:') || rawHref.startsWith('mailto:') || rawHref.startsWith('tel:')) {
                            continue;
                        }
                        const style = window.getComputedStyle(a);
                        if (style.display === 'none' || style.visibility === 'hidden' || a.getAttribute('aria-hidden') === 'true') {
                            continue;
                        }

                        let absUrl;
                        try {
                            absUrl = new URL(rawHref, window.location.href).href;
                            const parsed = new URL(absUrl);
                            if (parsed.hostname.includes('google.') && parsed.pathname === '/url') {
                                const target = parsed.searchParams.get('q') || parsed.searchParams.get('url');
                                if (target && (target.startsWith('http://') || target.startsWith('https://'))) {
                                    absUrl = target;
                                }
                            }
                        } catch {
                            continue;
                        }

                        const heading = a.querySelector('h1, h2, h3, h4, h5, h6');
                        let text = '';
                        if (heading && heading.innerText) {
                            const headingText = heading.innerText.replace(/\s+/g, ' ').trim();
                            // Capturar também o nome do site/fonte fora do heading (ex: span .VuuXrf no Google) ignorando <cite>
                            const siteBadge = a.querySelector('.VuuXrf') || (a.closest('.MjjYud, .g') && a.closest('.MjjYud, .g').querySelector('.VuuXrf'));
                            let extraText = '';
                            if (siteBadge && siteBadge.innerText) {
                                extraText = siteBadge.innerText.replace(/\s+/g, ' ').trim();
                            } else {
                                const clone = a.cloneNode(true);
                                clone.querySelectorAll('h1, h2, h3, h4, h5, h6, cite, svg').forEach(el => el.remove());
                                extraText = (clone.innerText || '').replace(/https?:\/\/\S+/gi, '').replace(/\s+/g, ' ').trim();
                            }

                            if (extraText && extraText.length <= 50 && !headingText.toLowerCase().includes(extraText.toLowerCase())) {
                                text = `${headingText} - ${extraText}`;
                            } else {
                                text = headingText;
                            }
                        } else {
                            const clone = a.cloneNode(true);
                            clone.querySelectorAll('cite, svg').forEach(el => el.remove());
                            text = (
                                a.getAttribute('aria-label') ||
                                clone.innerText ||
                                a.getAttribute('title') ||
                                ''
                            ).replace(/https?:\/\/\S+/gi, '').replace(/\s+/g, ' ').trim();
                        }

                        if (text.length < 2) continue;

                        if (isSearchPage) {
                            if (blockedSearchTexts.has(text.toLowerCase()) || absUrl.includes('udm=50') || absUrl.includes('accounts.google.')) {
                                continue;
                            }
                        }

                        const dedupKey = absUrl.replace(/\/$/, '');
                        if (seen.has(dedupKey)) continue;
                        seen.add(dedupKey);

                        const item = { text: text.slice(0, 120), url: absUrl };
                        let linkHost = '';
                        try {
                            linkHost = new URL(absUrl).hostname.toLowerCase();
                        } catch { }

                        if (isSearchPage && linkHost && (linkHost === location.hostname.toLowerCase() || linkHost.includes('google.'))) {
                            internalLinks.push(item);
                        } else {
                            externalLinks.push(item);
                        }

                        if ((externalLinks.length + internalLinks.length) >= 500) break;
                    }
                    if ((externalLinks.length + internalLinks.length) >= 500) break;
                }
                return [...externalLinks, ...internalLinks].slice(0, 500);
            }
        });

        const liveLinks = results && results[0] && results[0].result ? results[0].result : [];
        if (liveLinks.length > 0) {
            return findBestPageLink(cmd, liveLinks, tab.url);
        }
    } catch (e) {
        console.warn('Falha ao buscar links no DOM ativo:', e);
    }
    return null;
}

async function handleNavigateVoiceCommand(cmd) {
    const state = await getState();
    const searchSources = Array.isArray(state.searchSources) ? state.searchSources : [];
    const pageLinks = Array.isArray(state.pageLinks) ? state.pageLinks : [];

    // 1. Há opções de uma busca recente: número ("abrir opção 4", "abrir a quarta", "abrir o último"),
    //    "abrir" sozinho (repete as opções) ou o nome do site ("abrir UOL")
    if (searchSources.length > 0) {
        if (isOptionSelectionCommand(cmd)) {
            const parsed = parseOptionNumber(cmd, searchSources.length);
            if (parsed.outOfRange !== undefined) {
                const total = searchSources.length;
                announce(
                    total === 1
                        ? 'Só há 1 opção. Diga abrir opção 1.'
                        : `Só há ${total} opções. Diga abrir opção, seguido de um número de 1 a ${total}.`,
                    false
                );
                return;
            }
            await openSearchOption(searchSources[parsed.index], parsed.number);
            return;
        }

        if (isBareOpenCommand(cmd)) {
            announce(buildOptionsSpeech(searchSources));
            return;
        }

        const named = matchSourceBySite(cmd, searchSources);
        if (named) {
            await openSearchOption(named.source, named.number);
            return;
        }
    }

    // 2. Navegação contextual: links extraídos na última página resumida/orientada
    if (pageLinks.length > 0) {
        const match = findBestPageLink(cmd, pageLinks, state.analyzedUrl || null);
        if (match) {
            await clearState(['pageLinks']); // Limpa memória após navegar com sucesso
            await navigateToUrl(match.url, false, "Navegando para " + match.text, "Abrindo " + match.text);
            return;
        }
    }

    // 3. Fallback direto nos links visíveis do DOM da aba ativa (caso o link não estivesse em pageLinks)
    const liveMatch = await findLinkInActiveTabDom(cmd);
    if (liveMatch) {
        await clearState(['pageLinks']);
        await navigateToUrl(liveMatch.url, false, "Navegando para " + liveMatch.text, "Abrindo " + liveMatch.text);
        return;
    }

    // 4. Navegação direta para site conhecido (KNOWN_SITES) ou domínio explícito (.com, .com.br, etc.)
    const extracted = extractUrlFromSpeech(cmd);
    if (extracted) {
        await navigateToUrl(extracted.url, false, "Navegando para " + extracted.url, extracted.spokenMessage);
        return;
    }

    // 5. Se o usuário usou o comando "navegar" (sem "abrir") com um termo livre (ex: "valor do euro navegar"),
    // faz a pesquisa no Google como atalho de navegação.
    const targetName = extractSpokenTargetName(cmd);
    if (targetName && cmd.includes("navegar") && !cmd.includes("abrir")) {
        const searchUrl = 'https://www.google.com.br/search?q=' + encodeURIComponent(targetName);
        await navigateToUrl(searchUrl, false, 'Pesquisando por "' + targetName + '"', 'Pesquisando por ' + targetName);
        return;
    }

    // 6. Nenhuma opção ou site correspondente encontrado — avisar o usuário sem sair da página
    const spokenError = targetName
        ? `Não encontrei a opção ${targetName} nesta página. Tente dizer abrir seguido de outra opção disponível, ou diga buscar seguido do que deseja pesquisar.`
        : `Não consegui identificar qual opção você deseja abrir nesta página. Tente dizer abrir seguido do nome da seção, ou diga buscar seguido do que deseja pesquisar.`;

    safeSendMessage({
        action: "processResult",
        success: false,
        message: spokenError,
        ai_response: spokenError
    });
}

function handleSendDomRequest(request) {
    // Requisição para a API local rodando no docker
    fetch('http://localhost:8000/api/process-dom', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify(request.data)
    })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP Error status: ${response.status}`);
            }
            return response.json();
        })
        .then(async data => {
            // Se houver resposta da IA, enviar ela junto
            const aiResponse = data.data?.ai_response || null;
            const searchSources = data.data?.search_sources || null;
            const pageLinks = data.data?.page_links || null;

            if (searchSources && searchSources.length > 0) {
                await setState({ searchSources });
                await clearState(['pageLinks']);
            } else if (request.data && request.data.command === 'buscar') {
                // Busca sem resultados: descarta opções antigas para não abrir algo de outra busca
                await clearState(['searchSources']);
            }

            // Salvar links da página para permitir navegação contextual por voz
            // e limpar opções de busca antigas para que não interfiram no comando "abrir"
            if (pageLinks && pageLinks.length > 0) {
                await setState({ pageLinks });
                await clearState(['searchSources']);
            }

            safeSendMessage({
                action: "processResult",
                success: true,
                message: data.message || "Processado com sucesso.",
                ai_response: aiResponse,
                search_sources: searchSources
            });
        })
        .catch(error => {
            safeSendMessage({
                action: "processResult",
                success: false,
                message: error.message || "Erro de rede"
            });
        });
}

// --- Função reutilizável para abrir/fechar a janela de voz ---
function openVoiceWindow() {
    if (voiceWindowId !== null) {
        chrome.windows.update(voiceWindowId, { focused: true });
    } else {
        chrome.windows.create({
            url: 'voice.html',
            type: 'popup',
            width: 600,
            height: 400
        }, (win) => {
            voiceWindowId = win.id;
            chrome.storage.local.set({ isVoiceEnabled: true });
        });
    }
}

function closeVoiceWindow() {
    if (voiceWindowId !== null) {
        chrome.windows.remove(voiceWindowId);
        voiceWindowId = null;
        chrome.storage.local.set({ isVoiceEnabled: false });
    }
}

chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    if (request.action === "sendDom") {
        handleSendDomRequest(request);
    }

    if (request.action === "toggleVoice") {
        if (request.enabled) {
            openVoiceWindow();
        } else {
            closeVoiceWindow();
        }
    }

    if (request.action === "voiceCommand") {
        console.log("Received voice command in background:", request.command);
        const cmd = request.command;

        // --- Repetir as opções da última busca ---
        if (cmd.includes("repetir")) {
            getState().then(state => {
                const sources = Array.isArray(state.searchSources) ? state.searchSources : [];
                if (sources.length > 0) {
                    announce(buildOptionsSpeech(sources));
                } else {
                    announce('Não há opções para repetir. Diga buscar seguido do que deseja pesquisar.', false);
                }
            });
            return;
        }

        // --- Navegação por voz (links da página, opções de busca ou sites conhecidos) ---
        const isNavigateCmd = cmd.includes("abrir") || cmd.includes("navegar") || cmd.includes("ir para") || cmd.includes("ir ao");

        if (isNavigateCmd) {
            handleNavigateVoiceCommand(cmd);
            return;
        }

        // --- Comando de ajuda: lista todos os comandos disponíveis ---
        if (cmd.includes("comandos")) {
            const helpText =
                "Aqui estão os comandos de voz disponíveis. " +
                "Diga Resumir ou Resumo para que eu leia e resuma a página atual. " +
                "Diga Orientar, Orientação, Ajudar ou Ajuda para receber orientação sobre o que fazer na página. " +
                "Diga Buscar, Pesquisar ou Procurar seguido do que você quer encontrar, e eu faço a busca por você. " +
                "Diga Abrir, Navegar, Ir para ou Ir ao seguido do nome do site ou seção da página para navegar diretamente. " +
                "Depois de uma busca, diga Abrir opção 1, Abrir opção 2, e assim por diante, ou diga Abrir seguido do nome do site. Diga Repetir opções para ouvir a lista de novo. " +
                "E é claro, diga Comandos a qualquer momento para ouvir essa lista novamente.";
            safeSendMessage({
                action: "processResult",
                success: true,
                message: "Comandos disponíveis listados.",
                ai_response: helpText
            });
            return;
        }

        // --- Comandos que precisam do DOM da página atual ---
        if (cmd.includes("resumir") || cmd.includes("resumo")) {
            captureCurrentTabDom('resumir', cmd);
        } else if (cmd.includes("orientar") || cmd.includes("ajudar") || cmd.includes("orientação") || cmd.includes("ajuda")) {
            captureCurrentTabDom('orientar', cmd);
        } else if (cmd.includes("buscar") || cmd.includes("pesquisar") || cmd.includes("procurar")) {
            // Buscar NÃO precisa do DOM da página — envia sem contexto de HTML
            handleSearchWithoutDom(cmd);
        }
    }
});

// --- Função de matching textual e de domínio para links da página ---
// Compara a fala do usuário com o texto e o domínio dos links extraídos, retornando o melhor match.
function findBestPageLink(cmd, links, currentPageUrl = null) {
    const stopWords = new Set([
        'quero', 'gostaria', 'queria', 'favor', 'por', 'abrir', 'navegar',
        'para', 'ir', 'ao', 'a', 'o', 'os', 'as', 'e', 'site', 'pagina',
        'link', 'de', 'do', 'da', 'dos', 'das', 'um', 'uma', 'em', 'no', 'na',
        'me', 'leve', 'opcao', 'secao', 'sessao', 'menu', 'aba', 'area',
        'portal', 'jornal', 'canal'
    ]);

    // Normaliza texto: remove acentos, pontuação, lowercase, retém apenas palavras relevantes únicas
    function normalizeWords(text, dedup = false) {
        const words = text
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^\w\s]/g, ' ')
            .split(/\s+/)
            .filter(w => w.length > 1 && !stopWords.has(w));
        return dedup ? [...new Set(words)] : words;
    }

    // Normaliza plural/singular em português para comparação de radical exato
    function stemPt(word) {
        if (word.length < 3) return word;
        return word
            .replace(/oes$/, 'ao')
            .replace(/aes$/, 'ao')
            .replace(/ais$/, 'al')
            .replace(/eis$/, 'el')
            .replace(/ois$/, 'ol')
            .replace(/ns$/, 'm')
            .replace(/res$/, 'r')
            .replace(/zes$/, 'z')
            .replace(/s$/, '');
    }

    // Extrai tokens relevantes do domínio de um link externo (ex: wise.com -> ['wise'], bcb.gov.br -> ['bcb', 'banco', 'central', 'brasil'])
    function extractDomainTokens(linkUrl, baseHost) {
        try {
            const host = new URL(linkUrl).hostname.toLowerCase();
            if (!host || host === baseHost || (baseHost.includes('google.') && host.includes('google.'))) {
                return [];
            }
            const ignoredTlds = new Set(['www', 'com', 'br', 'org', 'net', 'gov', 'edu', 'io', 'app', 'pt', 'en', 'co', 'm', 'web']);
            const tokens = host.split(/[.\-]/).filter(t => t.length >= 2 && !ignoredTlds.has(t));

            // Aliases conhecidos de domínios brasileiros cujas siglas diferem do nome falado
            if (host.includes('bcb.gov.br')) {
                tokens.push('banco', 'central', 'brasil');
            }
            return [...new Set(tokens)];
        } catch {
            return [];
        }
    }

    let baseHost = '';
    if (currentPageUrl) {
        try {
            baseHost = new URL(currentPageUrl).hostname.toLowerCase();
        } catch { }
    }

    const targetClause = extractTargetClause(cmd);
    const cmdWords = normalizeWords(targetClause, true);
    if (cmdWords.length === 0) return null;

    let bestLink = null;
    let bestScore = 0;

    for (const link of links) {
        const linkWords = normalizeWords(link.text, false);
        const domainWords = extractDomainTokens(link.url, baseHost);
        if (linkWords.length === 0 && domainWords.length === 0) continue;

        let score = 0;
        let matchedCmdWords = 0;
        let exactOrStemMatches = 0;
        let domainMatches = 0;

        for (const w of cmdWords) {
            const wStem = stemPt(w);

            if (linkWords.includes(w)) {
                // Match exato da palavra no texto do link
                score += 15;
                matchedCmdWords++;
                exactOrStemMatches++;
            } else if (
                w.length >= 3 &&
                linkWords.some(lw => lw.length >= 3 && stemPt(lw) === wStem)
            ) {
                // Match singular/plural (ex: "noticia" vs "noticias", "cotacao" vs "cotacoes")
                score += 14;
                matchedCmdWords++;
                exactOrStemMatches++;
            } else if (
                domainWords.includes(w) ||
                (w.length >= 3 && domainWords.some(dw => stemPt(dw) === wStem))
            ) {
                // Match direto no domínio do site (ex: "wise" em wise.com, "uol" em economia.uol.com.br)
                score += 14;
                matchedCmdWords++;
                exactOrStemMatches++;
                domainMatches++;
            } else if (
                w.length >= 5 &&
                linkWords.some(lw => lw.length >= 5 && (lw.startsWith(w) || w.startsWith(lw)))
            ) {
                // Match por prefixo apenas para palavras com >= 5 letras (evita falsos positivos como "ia" em "noticias" ou "acao" em "cotacao")
                score += 6;
                matchedCmdWords++;
            }
        }

        if (matchedCmdWords === 0) continue;

        // Se o usuário falou 2 ou mais palavras (ex: "Banco Central do Brasil" ou "UOL Economia"),
        // exigir que pelo menos 2 palavras deem match (a menos que uma delas seja o domínio exclusivo do site)
        // para não abrir qualquer link genérico que só contenha "Brasil" ou "Economia".
        if (cmdWords.length >= 2 && matchedCmdWords < 2 && domainMatches === 0) {
            continue;
        }

        // Bônus quando todas as palavras relevantes do comando aparecem no link/domínio
        if (matchedCmdWords === cmdWords.length) {
            score += 25;

            // Bônus alto para match exato de seção/link curto (ex: link "Notícias" vs manchete longa contendo "notícias")
            if (exactOrStemMatches === cmdWords.length && linkWords.length === cmdWords.length) {
                score += 50;
            }
        }

        // Critério de especificidade: links mais curtos e diretos ganham bônus sobre títulos longos
        score += Math.max(0, 10 - linkWords.length);

        if (score > bestScore) {
            bestScore = score;
            bestLink = link;
        }
    }

    return bestScore >= 14 ? bestLink : null;
}

// --- Invalidar memória de links ao detectar navegação na aba analisada ---
chrome.tabs.onUpdated.addListener(async (tabId, changeInfo) => {
    if (!changeInfo.url) return;
    const { analyzedTabId } = await chrome.storage.session.get('analyzedTabId');
    if (tabId === analyzedTabId) {
        console.log('Tab navigated — clearing page links memory.');
        await clearState(['pageLinks', 'analyzedTabId', 'analyzedUrl']);
    }
});

// --- Atalho de teclado para ativar/desativar voz ---
chrome.commands.onCommand.addListener((command) => {
    if (command === 'toggle-voice') {
        if (voiceWindowId !== null) {
            closeVoiceWindow();
            safeSendMessage({ action: "voiceStatus", status: "ended" });
        } else {
            openVoiceWindow();
        }
    }
});

chrome.windows.onRemoved.addListener((windowId) => {
    if (windowId === voiceWindowId) {
        voiceWindowId = null;
        chrome.storage.local.set({ isVoiceEnabled: false });
        safeSendMessage({ action: "voiceStatus", status: "ended" });
    }
});
