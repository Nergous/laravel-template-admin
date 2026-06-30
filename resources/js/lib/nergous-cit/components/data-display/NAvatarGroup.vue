<script setup>
import { computed } from "vue";
import NAvatar from "./NAvatar.vue";

// NAvatarGroup — overlapping stack of avatars with a "+N" overflow chip.
// max = how many to render before collapsing the rest into the counter.
// items: array of names (strings)
const props = defineProps({
    items: { type: Array, default: () => [] },
    max: { type: Number, default: 4 },
    size: { type: Number, default: 38 },
});
const shown = computed(() => props.items.slice(0, props.max));
const hidden = computed(() => props.items.slice(props.max));
const extra = computed(() => hidden.value.length);
// overlap tracks avatar size (~35%) so the surface ring stays consistent across sizes
const overlap = computed(() => "-" + Math.round(props.size * 0.35) + "px");
</script>

<template>
    <div class="n-avatar-group">
        <NAvatar
            v-for="n in shown"
            :key="n"
            :name="n"
            :alt="n"
            :size="size"
            :square="false"
            class="n-avatar-group__item"
        />
        <span
            v-if="extra"
            class="n-avatar-group__more"
            :title="hidden.join(', ')"
            :style="{
                width: size + 'px',
                height: size + 'px',
                fontSize: Math.round(size * 0.34) + 'px',
                marginLeft: overlap,
            }"
            >+{{ extra }}</span
        >
    </div>
</template>

<style scoped>
.n-avatar-group {
    display: inline-flex;
}
.n-avatar-group__item {
    border: 2.5px solid var(--surface);
    border-radius: 50%;
}
.n-avatar-group__item:not(:first-child) {
    margin-left: v-bind(overlap);
}
.n-avatar-group__more {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: var(--surface-3);
    color: var(--text-2);
    font-weight: 700;
    border: 2.5px solid var(--surface);
}
</style>
