// nergous-cit DS v1.0.0 — vendored 2026-06-22. Vue 3 entry point.
// Usage: import { NButton, useToast } from '@/lib/nergous-cit'
//
// Named imports only. Global registration via app.use() is intentionally not
// supported (editor autocomplete + a page's real dependencies stay visible).
// The components/<category>/ layout is an implementation detail — import from
// this barrel, never via deep paths.
import "./styles/tokens.css";

// primitives
import NIcon from "./components/primitives/NIcon.vue";

// forms
import NButton from "./components/forms/NButton.vue";
import NFormField from "./components/forms/NFormField.vue";
import NInput from "./components/forms/NInput.vue";
import NTextarea from "./components/forms/NTextarea.vue";
import NRichText from "./components/forms/NRichText.vue";
import NSelect from "./components/forms/NSelect.vue";
import NCheckbox from "./components/forms/NCheckbox.vue";
import NRadioGroup from "./components/forms/NRadioGroup.vue";
import NSwitch from "./components/forms/NSwitch.vue";
import NSegmented from "./components/forms/NSegmented.vue";
import NDropzone from "./components/forms/NDropzone.vue";

// data-display
import NBadge from "./components/data-display/NBadge.vue";
import NAvatar from "./components/data-display/NAvatar.vue";
import NAvatarGroup from "./components/data-display/NAvatarGroup.vue";
import NCard from "./components/data-display/NCard.vue";
import NStatCard from "./components/data-display/NStatCard.vue";
import NActivityRow from "./components/data-display/NActivityRow.vue";
import NTabs from "./components/data-display/NTabs.vue";
import NDataTable from "./components/data-display/NDataTable.vue";
import NPagination from "./components/data-display/NPagination.vue";

// feedback
import NAlert from "./components/feedback/NAlert.vue";
import NToaster from "./components/feedback/NToaster.vue";
import NProgress from "./components/feedback/NProgress.vue";
import NSpinner from "./components/feedback/NSpinner.vue";
import NSkeleton from "./components/feedback/NSkeleton.vue";
import NTooltip from "./components/feedback/NTooltip.vue";
import NEmptyState from "./components/feedback/NEmptyState.vue";

// overlays
import NModal from "./components/overlays/NModal.vue";
import NDrawer from "./components/overlays/NDrawer.vue";
import NDropdown from "./components/overlays/NDropdown.vue";
import NCommandPalette from "./components/overlays/NCommandPalette.vue";
import NLightbox from "./components/overlays/NLightbox.vue";

// navigation
import NSidebar from "./components/navigation/NSidebar.vue";
import NTopbar from "./components/navigation/NTopbar.vue";
import NBrand from "./components/navigation/NBrand.vue";
import NStepper from "./components/navigation/NStepper.vue";
import NWizard from "./components/navigation/NWizard.vue";
import NAnchorNav from "./components/navigation/NAnchorNav.vue";
import NAnchoredForm from "./components/navigation/NAnchoredForm.vue";

import {
    useTheme,
    THEME_STORAGE_KEY,
    DENSITY_STORAGE_KEY,
} from "./composables/useTheme.js";
import { useToast } from "./composables/useToast.js";
import { useScrollSpy } from "./composables/useScrollSpy.js";

import { createFormat, toDate } from "./utils/format.js";

export {
    NIcon,
    NButton,
    NFormField,
    NInput,
    NSelect,
    NTextarea,
    NRichText,
    NSwitch,
    NCheckbox,
    NRadioGroup,
    NSegmented,
    NBadge,
    NAvatar,
    NAvatarGroup,
    NCard,
    NAlert,
    NTabs,
    NModal,
    NDrawer,
    NToaster,
    NSidebar,
    NTopbar,
    NDataTable,
    NPagination,
    NStatCard,
    NActivityRow,
    NDropzone,
    NEmptyState,
    NProgress,
    NSkeleton,
    NSpinner,
    NTooltip,
    NDropdown,
    NCommandPalette,
    NLightbox,
    NBrand,
    NStepper,
    NWizard,
    NAnchorNav,
    NAnchoredForm,
    useTheme,
    THEME_STORAGE_KEY,
    DENSITY_STORAGE_KEY,
    useToast,
    useScrollSpy,
    createFormat,
    toDate,
};
