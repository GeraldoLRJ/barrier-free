let voiceWindowId = null;
let lastSearchSources = null;
let lastPageLinks = null;       // Links extraídos da última página resumida/orientada
let lastAnalyzedTabId = null;   // ID da aba analisada (para invalidar ao trocar de URL)

// Mapeia palavras ordinais/cardinais para índices (0-based)
const NUMBER_WORDS = {
    'um': 0, 'uma': 0, 'primeiro': 0, 'primeira': 0, '1': 0,
    'dois': 1, 'duas': 1, 'segundo': 1, 'segunda': 1, '2': 1,
    'três': 2, 'tres': 2, 'terceiro': 2, 'terceira': 2, '3': 2,
    'quatro': 3, 'quarto': 3, 'quarta': 3, '4': 3,
    'cinco': 4, 'quinto': 4, 'quinta': 4, '5': 4,
};

function detectSourceIndex(cmd) {
    // Normalizar texto: remover acentos para facilitar matching
    const normalized = cmd.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    for (const [word, index] of Object.entries(NUMBER_WORDS)) {
        const wordNorm = word.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        if (normalized.includes(wordNorm)) {
            return index;
        }
    }
    return 0; // default: primeira fonte
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
        chrome.runtime.sendMessage({
            action: "processResult",
            success: true,
            message: successMessage,
            ai_response: aiResponse
        });
    }
}

async function captureCurrentTabDom(command = 'analisar', userPrompt = null) {
    // FIX: Pegar a aba ativa da janela normal do navegador, ignorando o popup de voz
    let [tab] = await chrome.tabs.query({ active: true, windowType: 'normal' });

    // Salvar o tabId da aba que está sendo analisada para detectar navegação posterior
    if (tab) {
        lastAnalyzedTabId = tab.id;
    }

    if (tab) {
        // Verifica se a aba não é uma página restrita do próprio Chrome
        if (tab.url && (tab.url.startsWith("chrome://") || tab.url.startsWith("chrome-extension://"))) {
            console.error("Tentativa de injetar em página restrita do Chrome");
            chrome.runtime.sendMessage({
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
                return {
                    url: window.location.href,
                    html_content: document.body.innerHTML
                };
            }
        }, (injectionResults) => {
            if (chrome.runtime.lastError) {
                console.error("Erro de injeção:", chrome.runtime.lastError.message);
                chrome.runtime.sendMessage({
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

// --- Extrai uma URL navegável a partir do texto falado pelo usuário ---
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
        'uol': 'https://www.uol.com.br',
        'terra': 'https://www.terra.com.br',
        'nubank': 'https://www.nubank.com.br',
    };

    const normalized = cmd.toLowerCase();

    // Verifica se algum site conhecido foi mencionado
    for (const [name, url] of Object.entries(KNOWN_SITES)) {
        if (normalized.includes(name)) {
            return { url: url, spokenMessage: 'Abrindo o site ' + name };
        }
    }

    // Detecta se o usuário falou um domínio diretamente (ex: "abrir globo.com")
    const domainMatch = normalized.match(/([a-z0-9\-]+\.(com|com\.br|org|net|gov\.br|br|io|app)(\.[a-z]{2})?)/i);
    if (domainMatch) {
        return { url: 'https://' + domainMatch[0], spokenMessage: 'Abrindo ' + domainMatch[0] };
    }

    // Sem site identificado — usar o Google como fallback com a frase como busca
    const stopWords = ['quero', 'gostaria', 'queria', 'favor', 'por', 'buscar', 'pesquisar', 'abrir', 'navegar', 'para', 'ir', 'ao', 'a', 'o', 'site', 'página', 'pagina', 'de', 'do', 'da', 'um', 'uma'];
    const terms = normalized.split(/\s+/).filter(w => w.length > 1 && !stopWords.includes(w));
    if (terms.length > 0) {
        const query = terms.join(' ');
        return {
            url: 'https://www.google.com.br/search?q=' + encodeURIComponent(query),
            spokenMessage: 'Pesquisando por "' + query + '"'
        };
    }

    return null;
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
        .then(data => {
            // Se houver resposta da IA, enviar ela junto
            const aiResponse = data.data?.ai_response || null;
            const searchSources = data.data?.search_sources || null;
            const pageLinks = data.data?.page_links || null;

            if (searchSources && searchSources.length > 0) {
                lastSearchSources = searchSources;
            }

            // Salvar links da página para permitir navegação contextual por voz
            if (pageLinks && pageLinks.length > 0) {
                lastPageLinks = pageLinks;
            }

            chrome.runtime.sendMessage({
                action: "processResult",
                success: true,
                message: data.message || "Processado com sucesso.",
                ai_response: aiResponse,
                search_sources: searchSources
            });
        })
        .catch(error => {
            chrome.runtime.sendMessage({
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
            width: 450,
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

    if (request.action === "summarizeDom") {
        captureCurrentTabDom('resumir');
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

        // --- Navegação direta por voz (não precisa de DOM, funciona em qualquer página) ---
        // Detecta padrões como: "abrir youtube", "navegar para google", "abrir site amazon.com.br"
        const isNavigateCmd = cmd.includes("abrir") || cmd.includes("navegar") || cmd.includes("ir para") || cmd.includes("ir ao");

        if (isNavigateCmd) {
            // Se há fontes de busca salvas e o usuário está escolhendo uma delas
            if (lastSearchSources && lastSearchSources.length > 0 && (cmd.includes("fonte") || cmd.includes("resultado") || /\d/.test(cmd) || /\b(um|dois|três|tres|quatro|cinco|primeiro|segundo|terceiro)\b/.test(cmd))) {
                const sourceIndex = detectSourceIndex(cmd);
                const clampedIndex = Math.min(sourceIndex, lastSearchSources.length - 1);
                const source = lastSearchSources[clampedIndex];
                const sourceNumber = clampedIndex + 1;
                navigateToUrl(source.url, true, "Navegando para fonte " + sourceNumber + ": " + source.title, "Abrindo a fonte " + sourceNumber + ": " + source.title);
                return;
            }

            // --- Navegação contextual: links identificados na última página resumida/orientada ---
            if (lastPageLinks && lastPageLinks.length > 0) {
                const match = findBestPageLink(cmd, lastPageLinks);
                if (match) {
                    lastPageLinks = null; // Limpa memória após navegar
                    navigateToUrl(match.url, false, "Navegando para " + match.text, "Abrindo " + match.text);
                    return;
                }
            }

            // Navegação direta: extrai o site mencionado na fala
            const extracted = extractUrlFromSpeech(cmd);
            if (extracted) {
                navigateToUrl(extracted.url, false, "Navegando para " + extracted.url, extracted.spokenMessage);
                return;
            }
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

// --- Função de matching textual para links da página ---
// Compara a fala do usuário com o texto dos links extraídos, retornando o melhor match.
function findBestPageLink(cmd, links) {
    const stopWords = new Set(['quero', 'gostaria', 'queria', 'favor', 'por', 'abrir', 'navegar',
        'para', 'ir', 'ao', 'a', 'o', 'e', 'site', 'página', 'pagina', 'link', 'de', 'do',
        'da', 'um', 'uma', 'em', 'no', 'na', 'me', 'leve']);

    // Normaliza texto: remove acentos, lowercase, retém apenas palavras relevantes
    function normalize(text) {
        return text
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .split(/\s+/)
            .filter(w => w.length > 1 && !stopWords.has(w));
    }

    const cmdWords = normalize(cmd);
    if (cmdWords.length === 0) return null;

    let bestLink = null;
    let bestScore = 0;

    for (const link of links) {
        const linkWords = normalize(link.text);
        if (linkWords.length === 0) continue;

        // Pontuação: quantas palavras do comando existem no texto do link
        const matches = cmdWords.filter(w => linkWords.some(lw => lw.includes(w) || w.includes(lw)));
        const score = matches.length;

        if (score > bestScore) {
            bestScore = score;
            bestLink = link;
        }
    }

    // Exigir ao menos 1 palavra em comum para considerar válido
    return bestScore >= 1 ? bestLink : null;
}

// --- Invalidar memória de links ao detectar navegação na aba analisada ---
chrome.tabs.onUpdated.addListener((tabId, changeInfo) => {
    if (tabId === lastAnalyzedTabId && changeInfo.url) {
        console.log('Tab navigated — clearing page links memory.');
        lastPageLinks = null;
        lastAnalyzedTabId = null;
    }
});

// --- Atalho de teclado (Alt+Shift+B) para ativar/desativar voz ---
chrome.commands.onCommand.addListener((command) => {
    if (command === 'toggle-voice') {
        if (voiceWindowId !== null) {
            closeVoiceWindow();
            chrome.runtime.sendMessage({ action: "voiceStatus", status: "ended" });
        } else {
            openVoiceWindow();
        }
    }
});

chrome.windows.onRemoved.addListener((windowId) => {
    if (windowId === voiceWindowId) {
        voiceWindowId = null;
        chrome.storage.local.set({ isVoiceEnabled: false });
        chrome.runtime.sendMessage({ action: "voiceStatus", status: "ended" });
    }
});
