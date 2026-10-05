<script setup lang="ts">
import type { PropType } from "vue";
import { NPagination } from "nergous-ui-vue";

// Paginator of the admin lists: maps the Laravel paginator onto NPagination.
// Hidden while the list fits one page of every offered size (hideOnSinglePage
// with total); labels come from the locale (createLocale in app.ts).
defineProps({
    /** Pagination meta of the server response (a Pagination<T> fits). */
    paginator: {
        type: Object as PropType<{
            current_page: number;
            last_page: number;
            total: number;
        }>,
        required: true,
    },
    perPage: { type: Number, required: true },
    perPageOptions: {
        type: Array as PropType<number[]>,
        default: () => [10, 25, 50, 100],
    },
});

defineEmits<{
    "update:page": [page: number];
    "update:pageSize": [size: number];
}>();
</script>

<template>
    <NPagination
        class="page__pager"
        :page="paginator.current_page"
        :pages="paginator.last_page"
        :total="paginator.total"
        :page-size="perPage"
        :page-sizes="perPageOptions"
        jumpable
        hide-on-single-page
        @update:page="$emit('update:page', $event)"
        @update:page-size="$emit('update:pageSize', $event)"
    />
</template>
