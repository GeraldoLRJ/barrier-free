chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.action === "sendDom") {
    
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
      chrome.runtime.sendMessage({
        action: "processResult",
        success: true,
        message: data.message || "Processado com sucesso."
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
});
