// Extrai o conteúdo do body e a URL
const domData = {
  html_content: document.body.innerHTML,
  url: window.location.href
};

// Envia os dados para o background.js (Service Worker) para realizar a requisição externa
chrome.runtime.sendMessage({ action: "sendDom", data: domData });
