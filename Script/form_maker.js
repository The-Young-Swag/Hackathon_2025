// form_maker.js

// Sidebar toggle
function toggleSidebar() {
    document.querySelector('.sidebar').classList.toggle('minimized');
    document.querySelector('.main-content').classList.toggle('shifted');
}

// Add a new question
function addQuestion() {
    const container = document.getElementById('questions-container');
    const index = container.children.length;

    const questionCard = document.createElement('div');
    questionCard.className = 'question-card';
    questionCard.draggable = true;
    questionCard.dataset.index = index;

    questionCard.innerHTML = `
        <button type="button" class="remove-btn" onclick="removeQuestion(${index})">×</button>
        <label>
            Question Code
            <input type="text" name="questions[${index}][question_code]" required placeholder="e.g., CC1">
        </label>
        <label>
            Question Text
            <textarea name="questions[${index}][question_text]" required placeholder="e.g., Which of the following best describes..."></textarea>
        </label>
        <label>
            Question Type
            <select name="questions[${index}][type_id]">
                ${questionTypes.map(type => `
                    <option value="${type.type_id}">${type.type_name}</option>
                `).join('')}
            </select>
        </label>
        <button type="button" class="options-btn" onclick="openOptionsModal(${index})">Add Options</button>
        <div class="options-list" id="options-list-${index}"></div>
    `;

    container.appendChild(questionCard);
    setupDragAndDrop();
}

// Remove a question
function removeQuestion(index) {
    const questionCard = document.querySelector(`.question-card[data-index="${index}"]`);
    questionCard.remove();
    reindexQuestions();
}

// Reindex questions after removal or reordering
function reindexQuestions() {
    const container = document.getElementById('questions-container');
    const cards = container.querySelectorAll('.question-card');

    cards.forEach((card, newIndex) => {
        card.dataset.index = newIndex;
        card.querySelector('input[name*="[question_code]"]').name = `questions[${newIndex}][question_code]`;
        card.querySelector('textarea[name*="[question_text]"]').name = `questions[${newIndex}][question_text]`;
        card.querySelector('select[name*="[type_id]"]').name = `questions[${newIndex}][type_id]`;
        card.querySelector('.options-btn').setAttribute('onclick', `openOptionsModal(${newIndex})`);
        card.querySelector('.remove-btn').setAttribute('onclick', `removeQuestion(${newIndex})`);
        card.querySelector('.options-list').id = `options-list-${newIndex}`;
    });
}

// Drag-and-drop for reordering questions
function setupDragAndDrop() {
    const container = document.getElementById('questions-container');

    container.addEventListener('dragstart', (e) => {
        const card = e.target.closest('.question-card');
        if (card) card.classList.add('dragging');
    });

    container.addEventListener('dragend', (e) => {
        const card = e.target.closest('.question-card');
        if (card) card.classList.remove('dragging');
    });

    container.addEventListener('dragover', (e) => {
        e.preventDefault();
        const afterElement = getDragAfterElement(container, e.clientY);
        const dragging = document.querySelector('.dragging');
        if (!afterElement) {
            container.appendChild(dragging);
        } else {
            container.insertBefore(dragging, afterElement);
        }
    });

    container.addEventListener('drop', reindexQuestions);
}

function getDragAfterElement(container, y) {
    const cards = [...container.querySelectorAll('.question-card:not(.dragging)')];
    return cards.reduce((closest, card) => {
        const box = card.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
            return { offset, element: card };
        }
        return closest;
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}

// Options modal management
let currentQuestionIndex = null;

function openOptionsModal(index) {
    currentQuestionIndex = index;
    const modal = document.getElementById('options-modal');
    const optionsContainer = document.getElementById('options-container');
    optionsContainer.innerHTML = '';

    const optionsList = document.getElementById(`options-list-${index}`);
    const options = optionsList.querySelectorAll('.option');
    options.forEach((option, optIndex) => {
        addOptionToModal(optIndex, option.dataset.value, option.dataset.text);
    });

    modal.style.display = 'flex';
}

function closeOptionsModal() {
    document.getElementById('options-modal').style.display = 'none';
    currentQuestionIndex = null;
}

function addOptionToModal(index, value = '', text = '') {
    const container = document.getElementById('options-container');
    const optionItem = document.createElement('div');
    optionItem.className = 'option-item';
    optionItem.dataset.optionIndex = index;

    optionItem.innerHTML = `
        <input type="text" placeholder="Option Value (e.g., 1, SD)" value="${value}" class="option-value">
        <input type="text" placeholder="Option Text (e.g., Strongly Disagree)" value="${text}" class="option-text">
        <button type="button" class="remove-option-btn" onclick="removeOption(${index})">×</button>
    `;

    container.appendChild(optionItem);
}

function addOption() {
    const container = document.getElementById('options-container');
    const index = container.children.length;
    addOptionToModal(index);
}

function removeOption(index) {
    const optionItem = document.querySelector(`.option-item[data-option-index="${index}"]`);
    optionItem.remove();
    reindexOptions();
}

function reindexOptions() {
    const container = document.getElementById('options-container');
    const optionItems = container.querySelectorAll('.option-item');
    optionItems.forEach((item, newIndex) => {
        item.dataset.optionIndex = newIndex;
        item.querySelector('.remove-option-btn').setAttribute('onclick', `removeOption(${newIndex})`);
    });
}

function saveOptions() {
    const optionsContainer = document.getElementById('options-container');
    const optionsList = document.getElementById(`options-list-${currentQuestionIndex}`);
    optionsList.innerHTML = '';

    const optionItems = optionsContainer.querySelectorAll('.option-item');
    optionItems.forEach((item, index) => {
        const value = item.querySelector('.option-value').value;
        const text = item.querySelector('.option-text').value;
        if (value && text) {
            const optionDiv = document.createElement('div');
            optionDiv.className = 'option';
            optionDiv.dataset.value = value;
            optionDiv.dataset.text = text;
            optionDiv.innerHTML = `
                <input type="hidden" name="questions[${currentQuestionIndex}][options][${index}][value]" value="${value}">
                <input type="hidden" name="questions[${currentQuestionIndex}][options][${index}][text]" value="${text}">
                <p>${value}: ${text}</p>
            `;
            optionsList.appendChild(optionDiv);
        }
    });

    closeOptionsModal();
}