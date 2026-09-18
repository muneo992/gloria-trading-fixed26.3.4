'use strict';

const gallery = document.getElementById('gallery-list');
const orderField = document.getElementById('gallery-order');
const fileInput = document.getElementById('new_images');

function updateGalleryOrder() {
  if (!gallery || !orderField) return;
  const items = Array.from(gallery.querySelectorAll('.gallery-admin-item'));
  orderField.value = JSON.stringify(items.map(item => item.dataset.token));
  items.forEach((item, index) => {
    const label = item.querySelector('.gallery-position');
    if (label) label.textContent = index === 0 ? 'Main photo' : `Photo ${index + 1}`;
    const up = item.querySelector('.move-up');
    const down = item.querySelector('.move-down');
    if (up) up.disabled = index === 0;
    if (down) down.disabled = index === items.length - 1;
  });
}

function moveItem(event) {
  const button = event.target.closest('button');
  if (!button || !gallery) return;
  const item = button.closest('.gallery-admin-item');
  if (!item) return;
  if (button.classList.contains('remove-photo')) {
    item.remove();
    updateGalleryOrder();
    return;
  }
  if (button.classList.contains('move-up') && item.previousElementSibling) {
    gallery.insertBefore(item, item.previousElementSibling);
  }
  if (button.classList.contains('move-down') && item.nextElementSibling) {
    gallery.insertBefore(item.nextElementSibling, item);
  }
  updateGalleryOrder();
}

function removeNewPreviews() {
  if (!gallery) return;
  gallery.querySelectorAll('.gallery-admin-item.new-photo').forEach(item => item.remove());
}

function addNewPreviews() {
  if (!gallery || !fileInput) return;
  removeNewPreviews();
  Array.from(fileInput.files).forEach((file, index) => {
    const item = document.createElement('article');
    item.className = 'gallery-admin-item new-photo';
    item.dataset.token = `new:${index}`;

    const image = document.createElement('img');
    image.alt = '';
    image.src = URL.createObjectURL(file);
    image.addEventListener('load', () => URL.revokeObjectURL(image.src), {once: true});

    const description = document.createElement('div');
    const position = document.createElement('strong');
    position.className = 'gallery-position';
    const name = document.createElement('span');
    name.textContent = file.name;
    description.append(position, name);

    const actions = document.createElement('div');
    actions.className = 'gallery-actions';
    ['Move up', 'Move down', 'Remove'].forEach((label, actionIndex) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'button button-small ' + (actionIndex === 2 ? 'button-danger remove-photo' : 'button-secondary ' + (actionIndex === 0 ? 'move-up' : 'move-down'));
      button.textContent = label;
      actions.append(button);
    });
    item.append(image, description, actions);
    gallery.append(item);
  });
  updateGalleryOrder();
}

if (gallery) gallery.addEventListener('click', moveItem);
if (fileInput) fileInput.addEventListener('change', addNewPreviews);
updateGalleryOrder();
