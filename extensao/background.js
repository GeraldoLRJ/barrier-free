let voiceWindowId = null;
let lastSearchSources = null;

async function captureCurrentTabDom(command = 'analisar', userPrompt = null) {
    // FIX: Pegar a aba ativa da janela normal do navegador, ignorando o popup de voz
    let [tab] = await chrome.tabs.query({ active: true, windowType: 'normal' });
    
    if (tab) {
        // Verifica se a aba não é uma página restrita do próprio Chrome
        if (tab.url && (tab.url.startsWith("chrome://") || tab.url.startsWith("chrome-extension://"))) {
            console.error("Tentativa de injetar em página restrita do Chrome");
            chrome.runtime.sendMessage({
                action: "processResult",
                success: false,
                message: "O Chrome não permite capturar páginas internas. Teste em um site real!"
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

      if (searchSources && searchSources.length > 0) {
        lastSearchSources = searchSources;
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

      if (cmd.includes("resumir") || cmd.includes("resumo")) {
          captureCurrentTabDom('resumir', cmd);
      } else if (cmd.includes("orientar") || cmd.includes("ajudar") || cmd.includes("orientação") || cmd.includes("ajuda")) {
          captureCurrentTabDom('orientar', cmd);
      } else if (cmd.includes("buscar") || cmd.includes("pesquisar") || cmd.includes("procurar")) {
          captureCurrentTabDom('buscar', cmd);
      } else if ((cmd.includes("abrir") || cmd.includes("navegar")) && lastSearchSources && lastSearchSources.length > 0) {
          const source = lastSearchSources[0];
          chrome.tabs.create({ url: source.url });
          lastSearchSources = null;
          chrome.runtime.sendMessage({
              action: "processResult",
              success: true,
              message: "Navegando para: " + source.title,
              ai_response: "Abrindo a página: " + source.title
          });
      }
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
