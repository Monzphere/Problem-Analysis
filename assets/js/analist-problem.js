document.addEventListener('DOMContentLoaded', () => {
    class AnalistProblem {
        constructor() {
            this.CSRF_TOKEN_NAME = '_csrf_token';
            this.form = this.findFormWithCsrfToken();
            this.init();
        }

        findFormWithCsrfToken() {
            for (let form of document.forms) {
                if (form[this.CSRF_TOKEN_NAME]) {
                    return form;
                }
            }

            const tokenInput = document.querySelector(`input[name="${this.CSRF_TOKEN_NAME}"]`);
            if (tokenInput) {
                return tokenInput.closest('form') || document.forms[0];
            }

            return document.forms[0];
        }

        init() {
            // Adiciona o botão inicialmente
            this.addLTSButton();

            // Observer para mudanças no DOM
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    // Verifica se há mudanças relevantes para o botão LTS
                    if (mutation.target.classList &&
                        (mutation.target.classList.contains('flickerfreescreen') ||
                         mutation.target.classList.contains('list-table') ||
                         mutation.target.classList.contains('dashboard-grid-widget-contents') ||
                         mutation.target.classList.contains('dashboard-widget-problems') ||
                         mutation.target.classList.contains('table') ||
                         mutation.target.tagName === 'TBODY' ||
                         mutation.target.tagName === 'TR')) {
                        this.addLTSButton();
                    }
                });
            });

            // Observer específico para widgets da dashboard
            const dashboardObserver = new MutationObserver((mutations) => {
                let shouldUpdate = false;
                mutations.forEach((mutation) => {
                    if (mutation.target.classList &&
                        (mutation.target.classList.contains('dashboard-grid-widget-contents') ||
                         mutation.target.classList.contains('dashboard-widget-problems') ||
                         mutation.target.closest('.dashboard-grid-widget-contents'))) {
                        shouldUpdate = true;
                    }
                });

                if (shouldUpdate) {
                    setTimeout(() => {
                        this.addLTSButtonToDashboardWidgets();
                    }, 500);
                }
            });

            // Observar especificamente o dashboard se existir
            const dashboardContainer = document.querySelector('.dashboard-grid, .dashboard-container');
            if (dashboardContainer) {
                dashboardObserver.observe(dashboardContainer, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['class']
                });
            }

            // Observa o corpo do documento para mudanças
            observer.observe(document.body, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['class', 'style']
            });

            // Adiciona listener para o evento específico do Zabbix de atualização da tela
            document.addEventListener('zbx_reload', () => {
                this.addLTSButton();
            });

            // Executa periodicamente para capturar mudanças dinâmicas
            setInterval(() => {
                this.addLTSButton();
            }, 2000);

            // Executa periodicamente para capturar widgets da dashboard carregados dinamicamente
            setInterval(() => {
                this.addLTSButtonToDashboardWidgets();
            }, 3000);
        }

        addLTSButton() {
            // Adiciona botões na lista principal de problemas
            this.addLTSButtonToMainTable();

            // Adiciona botões nos widgets da dashboard
            this.addLTSButtonToDashboardWidgets();
        }

        addLTSButtonToMainTable() {
            const flickerfreescreen = document.querySelector('.flickerfreescreen');
            if (!flickerfreescreen) return;

            const tables = flickerfreescreen.querySelectorAll('table.list-table');
            tables.forEach(table => {
                const tbody = table.querySelector('tbody');
                if (!tbody) return;

                // Verificar se já adicionamos o cabeçalho da coluna LTS
                const headerRow = table.querySelector('thead tr');
                if (headerRow && !headerRow.querySelector('.lts-header')) {
                    const ltsHeader = document.createElement('th');
                    ltsHeader.className = 'lts-header';
                    ltsHeader.textContent = 'Details';
                    headerRow.appendChild(ltsHeader);
                }

                const rows = tbody.querySelectorAll('tr:not(.timeline-axis):not(.timeline-td)');
                rows.forEach(row => {
                    // Verifica se a linha não é uma linha de tempo e se já tem botão LTS
                    if (row.querySelector('.js-lts-button') ||
                        !row.querySelector('.problem-expand-td')) return;

                    // Extrai dados usando data-menu-popup (mais confiável)
                    const problemData = this.extractProblemDataFromMenuPopup(row);

                    if (problemData && problemData.eventid) {
                        this.addLTSColumnToRow(row, problemData);
                    }
                });
            });
        }

        addLTSButtonToDashboardWidgets() {
            // Buscar widgets de problemas na dashboard
            const selectors = [
                '.dashboard-grid-widget-contents .dashboard-widget-problems',
                '.dashboard-widget-problems',
                '.dashboard-grid-widget-contents table.list-table',
                '[class*="dashboard"][class*="widget"][class*="problem"] table.list-table',
                '.dashboard-grid-widget table.list-table'
            ];

            const problemWidgets = new Set();

            selectors.forEach(selector => {
                document.querySelectorAll(selector).forEach(element => {
                    if (element.tagName === 'TABLE') {
                        const widget = element.closest('.dashboard-grid-widget-contents, .dashboard-widget-problems') || element.parentElement;
                        if (widget) problemWidgets.add(widget);
                    } else {
                        problemWidgets.add(element);
                    }
                });
            });

            problemWidgets.forEach(widget => {
                const problemTable = widget.querySelector('table.list-table') || (widget.tagName === 'TABLE' ? widget : null);
                if (!problemTable) return;

                const tbody = problemTable.querySelector('tbody');
                if (!tbody) return;

                // Verificar se já adicionamos o cabeçalho da coluna LTS
                const headerRow = problemTable.querySelector('thead tr');
                if (headerRow && !headerRow.querySelector('.lts-header')) {
                    const ltsHeader = document.createElement('th');
                    ltsHeader.className = 'lts-header';
                    ltsHeader.textContent = 'Details';
                    headerRow.appendChild(ltsHeader);
                }

                const rows = tbody.querySelectorAll('tr:not(.timeline-axis):not(.timeline-td)');
                rows.forEach(row => {
                    // Verifica se já existe um botão LTS nesta linha
                    if (row.querySelector('.js-lts-button-widget')) return;

                    // Extrai dados usando data-menu-popup
                    const problemData = this.extractProblemDataFromMenuPopup(row);

                    if (problemData && problemData.eventid) {
                        this.addLTSColumnToRow(row, problemData, true);
                    }
                });
            });
        }

        extractProblemDataFromMenuPopup(row) {
            let eventid = null;
            let triggerid = null;
            let hostid = null;
            let hostname = '';
            let problemName = '';

            try {
                // Extrai eventid de links ou atributos
                const eventLink = row.querySelector('a[href*="eventid"]');
                if (eventLink) {
                    const match = eventLink.href.match(/eventid=(\d+)/);
                    if (match) {
                        eventid = match[1];
                    }
                }

                // Extrai hostid e triggerid usando data-menu-popup (método mais confiável)
                const hostElement = row.querySelector('[data-menu-popup*="hostid"]');
                const triggerElement = row.querySelector('[data-menu-popup*="triggerid"]');

                if (hostElement) {
                    try {
                        const hostData = JSON.parse(hostElement.getAttribute('data-menu-popup'));
                        if (hostData.data && hostData.data.hostid) {
                            hostid = hostData.data.hostid;
                            hostname = hostElement.textContent.trim();
                        }
                    } catch (e) {
                        console.warn('Erro ao parsear hostid:', e);
                    }
                }

                if (triggerElement) {
                    try {
                        const triggerData = JSON.parse(triggerElement.getAttribute('data-menu-popup'));
                        if (triggerData.data && triggerData.data.triggerid) {
                            triggerid = triggerData.data.triggerid;
                            problemName = triggerElement.textContent.trim();
                        }
                    } catch (e) {
                        console.warn('Erro ao parsear triggerid:', e);
                    }
                }

                // Se não conseguiu extrair pelos data-menu-popup, tenta métodos alternativos
                if (!hostid || !triggerid) {
                    const fallbackData = this.extractProblemDataFallback(row);
                    hostid = hostid || fallbackData.hostid;
                    triggerid = triggerid || fallbackData.triggerid;
                    hostname = hostname || fallbackData.hostname;
                    problemName = problemName || fallbackData.problemName;
                }

                //console.log('AnalistProblem - Dados extraídos via menu-popup:', {
                //    eventid: eventid,
                //    triggerid: triggerid,
                //    hostid: hostid,
                //    hostname: hostname,
                //    problemName: problemName
                //});

                return eventid ? {
                    eventid: eventid,
                    triggerid: triggerid,
                    hostid: hostid,
                    hostname: hostname,
                    problemName: problemName
                } : null;

            } catch (error) {
                //console.log('Erro ao extrair dados via menu-popup:', error);
                return null;
            }
        }

        extractProblemDataFallback(row) {
            let eventid = null;
            let triggerid = null;
            let hostid = null;
            let hostname = '';
            let problemName = '';

            try {
                // Tenta extrair eventid de links ou atributos
                const eventLink = row.querySelector('a[href*="eventid"]');
                if (eventLink) {
                    const match = eventLink.href.match(/eventid=(\d+)/);
                    if (match) {
                        eventid = match[1];
                    }
                }

                // Múltiplas tentativas para extrair triggerid
                let triggerLink = row.querySelector('a[href*="triggerids"]');
                if (triggerLink) {
                    const match = triggerLink.href.match(/triggerids.*?(\d+)/);
                    if (match) {
                        triggerid = match[1];
                    }
                }

                // Tenta também por triggers no singular
                if (!triggerid) {
                    triggerLink = row.querySelector('a[href*="triggerid"]');
                    if (triggerLink) {
                        const match = triggerLink.href.match(/triggerid.*?(\d+)/);
                        if (match) {
                            triggerid = match[1];
                        }
                    }
                }

                // Múltiplas tentativas para extrair hostid e hostname
                // 1. Procura por links com hostid na URL
                let hostLink = row.querySelector('a[href*="hostid"]');
                if (!hostLink) {
                    hostLink = row.querySelector('a[href*="filter_hostids"]');
                }
                if (!hostLink) {
                    hostLink = row.querySelector('a[href*="hostids"]');
                }

                if (hostLink) {
                    const hostMatch = hostLink.href.match(/(?:hostids?|filter_hostids).*?(\d+)/);
                    if (hostMatch) {
                        hostid = hostMatch[1];
                    }
                    hostname = hostLink.textContent.trim();
                }

                // 2. Se não encontrou, procura na coluna Host (geralmente 4ª ou 5ª coluna)
                if (!hostid || !hostname) {
                    const hostCells = row.querySelectorAll('td');
                    for (let i = 3; i <= 5 && i < hostCells.length; i++) {
                        const hostCell = hostCells[i];
                        const hostAnchor = hostCell.querySelector('a');
                        if (hostAnchor && hostAnchor.href.includes('hostid')) {
                            const match = hostAnchor.href.match(/hostid.*?(\d+)/);
                            if (match) {
                                hostid = match[1];
                                hostname = hostAnchor.textContent.trim();
                                break;
                            }
                        }
                    }
                }

                // 3. Se ainda não encontrou, tenta por data attributes ou outros padrões
                if (!hostid) {
                    // Procura por data-hostid no row ou elementos filhos
                    const elementWithHostid = row.querySelector('[data-hostid]');
                    if (elementWithHostid) {
                        hostid = elementWithHostid.getAttribute('data-hostid');
                    }
                }

                // 4. Se ainda não tem hostname, procura por texto que pode ser hostname
                if (!hostname && hostid) {
                    // Procura na célula que contém informações do host
                    const possibleHostCell = row.querySelector('td:nth-child(4), td:nth-child(5)');
                    if (possibleHostCell) {
                        const text = possibleHostCell.textContent.trim();
                        if (text && !text.includes('Problem') && text.length > 0) {
                            hostname = text;
                        }
                    }
                }

                // Extrai nome do problema
                const problemCell = row.querySelector('td:nth-child(3), td:nth-child(4)');
                if (problemCell) {
                    problemName = problemCell.textContent.trim();
                }

                // Se não conseguiu extrair eventid, tenta de outra forma
                if (!eventid) {
                    // Procura por qualquer link que contenha números que possam ser eventid
                    const allLinks = row.querySelectorAll('a[href*="action="]');
                    allLinks.forEach(link => {
                        const href = link.href;
                        if (href.includes('eventid')) {
                            const match = href.match(/eventid=(\d+)/);
                            if (match && !eventid) {
                                eventid = match[1];
                            }
                        }
                    });
                }

            } catch (error) {
                //console.log('Erro ao extrair dados da linha:', error);
                return null;
            }

            // Debug: log dos dados extraídos
            //console.log('AnalistProblem - Dados extraídos da linha:', {
            //    eventid: eventid,
            //    triggerid: triggerid,
            //    hostid: hostid,
            //    hostname: hostname,
            //    problemName: problemName
            //});

            return eventid ? {
                eventid: eventid,
                triggerid: triggerid,
                hostid: hostid,
                hostname: hostname,
                problemName: problemName
            } : null;
        }

        addLTSColumnToRow(row, problemData, isWidget = false) {
            // Verifica se já existe a coluna LTS nesta linha
            const existingButton = row.querySelector('.js-lts-button') || row.querySelector('.js-lts-button-widget');
            if (existingButton) return;

            // Cria o botão
            const button = document.createElement('button');
            button.className = isWidget ? 'btn-alt js-lts-button-widget' : 'btn-alt js-lts-button';
            button.innerHTML = 'Details';
            button.title = `Detail for: ${problemData.hostname} - ${problemData.problemName}`;

            // Cria a nova célula da tabela
            const td = document.createElement('td');
            td.className = 'lts-column-cell';
            td.appendChild(button);

            // Adiciona a célula no final da linha
            row.appendChild(td);

            // Adiciona evento de click
            button.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.openLTSModal(problemData);
            });
        }

        addButtonToRow(row, problemData) {
            // Encontra a última célula da linha para adicionar o botão
            const lastCell = row.lastElementChild;
            if (!lastCell) return;

            // Cria o botão
            const button = document.createElement('button');
            button.className = 'analist-lts-btn btn-link';
            button.innerHTML = 'LTS';
            button.title = 'Problem analysis';

            // Adiciona evento de click
            button.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.openLTSModal(problemData);
            });

            // Adiciona o botão à célula
            lastCell.appendChild(button);
        }

        openLTSModal(problemData) {
            //console.log('AnalistProblem - Abrindo modal com dados:', problemData);

            const params = new URLSearchParams({
                eventid: problemData.eventid,
                ...(problemData.triggerid && { triggerid: problemData.triggerid }),
                ...(problemData.hostid && { hostid: problemData.hostid }),
                ...(problemData.hostname && { hostname: problemData.hostname }),
                ...(problemData.problemName && { problem_name: problemData.problemName })
            });

            // URL do endpoint do nosso módulo
            const url = params.toString();
            //console.log('AnalistProblem - URL construída:', url);

            // Abre popup usando a função do Zabbix
            if (typeof PopUp !== 'undefined') {
                PopUp('problemanalist.view', url, {
                    dialogue_class: 'modal-popup-large',
                    draggable: true,
                    resizable: true
                });
            } else {
                // Fallback para abrir em nova janela
                window.open(url, 'analist-lts', 'width=1200,height=800,scrollbars=yes,resizable=yes');
            }
        }

        getCsrfToken() {
            if (this.form && this.form[this.CSRF_TOKEN_NAME]) {
                return this.form[this.CSRF_TOKEN_NAME].value;
            }

            const tokenInput = document.querySelector(`input[name="${this.CSRF_TOKEN_NAME}"]`);
            return tokenInput ? tokenInput.value : '';
        }
    }

    // Inicializa a classe
    new AnalistProblem();
});
