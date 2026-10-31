import './bootstrap';
import '@fontsource/inter/latin-400.css';
import '@fontsource/inter/latin-500.css';
import '@fontsource/inter/latin-600.css';
import '@fontsource/inter/latin-700.css';

import Alpine from 'alpinejs';
import { financeGuide } from './finance';
import { processingDocument } from './processing';
import { purchasingDocument } from './purchasing';
import { catalogItem } from './catalog';
import { productionForm } from './production';
import { packingForm, packRecipe } from './packing';

import { salesForm } from './sales';

window.Alpine = Alpine;
Alpine.data('financeGuide', financeGuide);
Alpine.data('salesForm',salesForm);
Alpine.data('processingDocument', processingDocument);
Alpine.data('purchasingDocument', purchasingDocument);
Alpine.data('catalogItem', catalogItem);
Alpine.data('productionForm', productionForm);
Alpine.data('packingForm',packingForm);
Alpine.data('packRecipe',packRecipe);
Alpine.start();
