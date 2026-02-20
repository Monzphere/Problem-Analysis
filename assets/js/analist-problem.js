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
            
            this.addLTSButton();

            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    
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

            const dashboardContainer = document.querySelector('.dashboard-grid, .dashboard-container');
            if (dashboardContainer) {
                dashboardObserver.observe(dashboardContainer, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['class']
                });
            }

            observer.observe(document.body, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['class', 'style']
            });

            document.addEventListener('zbx_reload', () => {
                this.addLTSButton();
            });

            setInterval(() => {
                this.addLTSButton();
            }, 2000);

            setInterval(() => {
                this.addLTSButtonToDashboardWidgets();
            }, 3000);
        }

        addLTSButton() {
            
            this.addLTSButtonToMainTable();

            this.addLTSButtonToDashboardWidgets();
        }

        addLTSButtonToMainTable() {
            const flickerfreescreen = document.querySelector('.flickerfreescreen');
            if (!flickerfreescreen) return;

            const tables = flickerfreescreen.querySelectorAll('table.list-table');
            tables.forEach(table => {
                const tbody = table.querySelector('tbody');
                if (!tbody) return;

                const headerRow = table.querySelector('thead tr');
                if (headerRow && !headerRow.querySelector('.mnz-lts-header')) {
                    const ltsHeader = document.createElement('th');
                    ltsHeader.className = 'mnz-lts-header';
                    ltsHeader.textContent = 'MonZGuru';
                    headerRow.appendChild(ltsHeader);
                }

                const rows = tbody.querySelectorAll('tr:not(.timeline-axis):not(.timeline-td)');
                rows.forEach(row => {
                    
                    if (row.querySelector('.js-lts-button') || 
                        !row.querySelector('.problem-expand-td')) return;

                    const problemData = this.extractProblemDataFromMenuPopup(row);
                    
                    if (problemData && problemData.eventid) {
                        this.addLTSColumnToRow(row, problemData);
                    }
                });
            });
        }

        addLTSButtonToDashboardWidgets() {
            
            const specificWidget = document.querySelector('.dashboard-grid-widget-contents.dashboard-widget-problems');

            const problemWidgets = new Set();

            if (specificWidget) {
                problemWidgets.add(specificWidget);
            }
            
            problemWidgets.forEach(widget => {
                const problemTable = widget.querySelector('table.list-table') || (widget.tagName === 'TABLE' ? widget : null);
                if (!problemTable) return;

                const tbody = problemTable.querySelector('tbody');
                if (!tbody) return;

                const headerRow = problemTable.querySelector('thead tr');
                if (headerRow && !headerRow.querySelector('.mnz-lts-header')) {
                    const ltsHeader = document.createElement('th');
                    ltsHeader.className = 'mnz-lts-header';
                    ltsHeader.textContent = 'MonZGuru';
                    headerRow.appendChild(ltsHeader);
                }

                const rows = tbody.querySelectorAll('tr:not(.timeline-axis):not(.timeline-td)');
                rows.forEach(row => {
                    
                    if (row.querySelector('.mnz-lts-button-widget')) return;

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
                
                const eventLink = row.querySelector('a[href*="eventid"]');
                if (eventLink) {
                    const match = eventLink.href.match(/eventid=(\d+)/);
                    if (match) {
                        eventid = match[1];
                    }
                }

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

                if (!hostid || !triggerid) {
                    const fallbackData = this.extractProblemDataFallback(row);
                    hostid = hostid || fallbackData.hostid;
                    triggerid = triggerid || fallbackData.triggerid;
                    hostname = hostname || fallbackData.hostname;
                    problemName = problemName || fallbackData.problemName;
                }

                return eventid ? {
                    eventid: eventid,
                    triggerid: triggerid,
                    hostid: hostid,
                    hostname: hostname,
                    problemName: problemName
                } : null;

            } catch (error) {
                
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
                
                const eventLink = row.querySelector('a[href*="eventid"]');
                if (eventLink) {
                    const match = eventLink.href.match(/eventid=(\d+)/);
                    if (match) {
                        eventid = match[1];
                    }
                }

                let triggerLink = row.querySelector('a[href*="triggerids"]');
                if (triggerLink) {
                    const match = triggerLink.href.match(/triggerids.*?(\d+)/);
                    if (match) {
                        triggerid = match[1];
                    }
                }

                if (!triggerid) {
                    triggerLink = row.querySelector('a[href*="triggerid"]');
                    if (triggerLink) {
                        const match = triggerLink.href.match(/triggerid.*?(\d+)/);
                        if (match) {
                            triggerid = match[1];
                        }
                    }
                }

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

                if (!hostid) {
                    
                    const elementWithHostid = row.querySelector('[data-hostid]');
                    if (elementWithHostid) {
                        hostid = elementWithHostid.getAttribute('data-hostid');
                    }
                }

                if (!hostname && hostid) {
                    
                    const possibleHostCell = row.querySelector('td:nth-child(4), td:nth-child(5)');
                    if (possibleHostCell) {
                        const text = possibleHostCell.textContent.trim();
                        if (text && !text.includes('Problem') && text.length > 0) {
                            hostname = text;
                        }
                    }
                }

                const problemCell = row.querySelector('td:nth-child(3), td:nth-child(4)');
                if (problemCell) {
                    problemName = problemCell.textContent.trim();
                }

                if (!eventid) {
                    
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
                
                return null;
            }

            return eventid ? {
                eventid: eventid,
                triggerid: triggerid,
                hostid: hostid,
                hostname: hostname,
                problemName: problemName
            } : null;
        }

        addLTSColumnToRow(row, problemData, isWidget = false) {
            
            const existingButton = row.querySelector('.mnz-lts-button') || row.querySelector('.mnz-lts-button-widget');
            if (existingButton) return;

            const button = document.createElement('button');
            button.className = isWidget ? 'btn-alt mnz-lts-button-widget' : 'btn-alt mnz-lts-button';
            button.innerHTML = 'Details';
            button.title = `Análise LTS: ${problemData.hostname} - ${problemData.problemName}`;

            const td = document.createElement('td');
            td.className = 'mnz-lts-column-cell';
            td.appendChild(button);

            row.appendChild(td);

            button.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.openLTSModal(problemData);
            });
        }

        addButtonToRow(row, problemData) {
            
            const lastCell = row.lastElementChild;
            if (!lastCell) return;

            const button = document.createElement('button');
            button.className = 'mnz-lts-btn btn-link';
            button.innerHTML = 'LTS';
            button.title = 'Análise LTS do Problema';

            button.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.openLTSModal(problemData);
            });

            lastCell.appendChild(button);
        }

        openLTSModal(problemData) {

            const params = new URLSearchParams({
                eventid: problemData.eventid,
                ...(problemData.triggerid && { triggerid: problemData.triggerid }),
                ...(problemData.hostid && { hostid: problemData.hostid }),
                ...(problemData.hostname && { hostname: problemData.hostname }),
                ...(problemData.problemName && { problem_name: problemData.problemName })
            });

            const url = params.toString();

            if (typeof PopUp !== 'undefined') {
                PopUp('problemanalist.view', url, {
                    dialogue_class: 'mnz-problemanalist-modal',
                    draggable: true,
                    resizable: true
                });
            } else {
                
                window.open(url, 'analist-lts', 'width=1100,height=700,scrollbars=yes,resizable=yes');
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

    new AnalistProblem();
});
