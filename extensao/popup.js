document.getElementById('processBtn').addEventListener('click', async () => {
  const statusDiv = document.getElementById('status');
  statusDiv.textContent = "Processando...";

  // Pega a tab ativa
  let [tab] = await chrome.tabs.query({ active: true, currentWindow: true });

  // Executa o script de injeção
  chrome.scripting.executeScript({
    target: { tabId: tab.id },
    files: ['content.js']
  }, (results) => {
    if (chrome.runtime.lastError) {
      statusDiv.textContent = "Erro: " + chrome.runtime.lastError.message;
      return;
    }
  });
});

// Escuta respostas do Service Worker
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.action === "processResult") {
    const statusDiv = document.getElementById('status');
    if (request.success) {
      statusDiv.style.color = "green";
      statusDiv.textContent = "Concluído! " + request.message;
    } else {
      statusDiv.style.color = "red";
      statusDiv.textContent = "Falha: " + request.message;
    }
  }
});
