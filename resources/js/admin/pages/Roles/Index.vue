<script setup lang="ts">
import { ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import {
    NCard,
    NBadge,
    NButton,
    NInput,
    NPagination,
    NEmptyState,
} from "nergous-ui-vue";
import ConfirmModal from "@/admin/components/ConfirmModal.vue";
import { useConfirm } from "@/admin/composables/useConfirm";
import { useIndexFilters } from "@/admin/composables/useIndexFilters";
import { can } from "@/lib/can";
import { formatNumber } from "@/lib/format";
import { swatchColor } from "@/lib/swatch";

const props = defineProps({
    roles: { type: Object, required: true },
    permissionsTotal: { type: Number, default: 0 },
    filters: { type: Object, default: () => ({}) },
});

const search = ref(props.filters.search ?? "");
const { reload, onSearch } = useIndexFilters("/admin/roles", () => ({
    search: search.value,
}));

const del = useConfirm();

function confirmDelete() {
    if (!del.payload) return;
    del.loading = true;
    router.delete(`/admin/roles/${del.payload.id}`, {
        preserveScroll: true,
        onFinish: () => del.close(),
    });
}
</script>

<template>
    <AdminLayout title="Роли" subtitle="Наборы прав доступа">
        <div class="page">
            <div class="page__toolbar">
                <div class="page__search">
                    <NInput
                        v-model="search"
                        icon="search"
                        placeholder="Поиск по названию"
                        @update:model-value="onSearch"
                    />
                </div>
                <NButton
                    v-if="can('roles.create')"
                    :as="Link"
                    href="/admin/roles/create"
                    variant="primary"
                    icon="plus"
                    >Создать</NButton
                >
            </div>

            <NEmptyState
                v-if="!roles.data.length"
                icon="shield"
                title="Роли не найдены"
                :description="
                    search
                        ? 'Попробуйте изменить запрос поиска.'
                        : 'Создайте первую роль, чтобы управлять доступом.'
                "
            >
                <NButton
                    v-if="can('roles.create')"
                    :as="Link"
                    href="/admin/roles/create"
                    variant="primary"
                    icon="plus"
                    >Создать роль</NButton
                >
            </NEmptyState>

            <template v-else>
                <div class="roles">
                    <NCard
                        v-for="role in roles.data"
                        :key="role.id"
                        hover
                        padding="var(--kpi-pad)"
                        class="roles__card"
                    >
                        <div class="role">
                            <div class="role__head">
                                <span
                                    class="role__swatch"
                                    :style="{
                                        background: swatchColor(role.name),
                                    }"
                                />
                                <h2 class="role__name">
                                    <Link
                                        :href="`/admin/roles/${role.id}`"
                                        class="role__name-link"
                                    >
                                        {{ role.name }}
                                    </Link>
                                </h2>
                                <NBadge size="sm">{{
                                    role.is_system ? "системная" : "кастомная"
                                }}</NBadge>
                                <div class="role__actions">
                                    <NButton
                                        v-if="role.can_edit"
                                        :as="Link"
                                        :href="`/admin/roles/${role.id}/edit`"
                                        variant="ghost"
                                        tone="accent"
                                        icon="edit"
                                        size="sm"
                                        :aria-label="`Редактировать роль ${role.name}`"
                                    />
                                    <NButton
                                        v-if="
                                            can('roles.delete') &&
                                            !role.is_system
                                        "
                                        variant="ghost"
                                        tone="danger"
                                        icon="trash"
                                        size="sm"
                                        aria-label="Удалить роль"
                                        @click="del.ask(role)"
                                    />
                                </div>
                            </div>

                            <div class="role__desc">
                                {{ role.description || "Описание не задано" }}
                            </div>

                            <div class="role__footer">
                                <div class="role__stat">
                                    <b class="role__num">{{
                                        formatNumber(role.users_count)
                                    }}</b>
                                    <span class="role__label"
                                        >пользователей</span
                                    >
                                </div>
                                <div class="role__stat">
                                    <b class="role__num"
                                        >{{ role.permissions_count }} /
                                        {{ permissionsTotal }}</b
                                    >
                                    <span class="role__label">разрешений</span>
                                </div>
                            </div>
                        </div>
                    </NCard>
                </div>

                <p class="roles__hint">
                    Откройте роль, чтобы посмотреть её разрешения и ссылку на
                    редактирование.
                </p>

                <div v-if="roles.last_page > 1" class="page__pager">
                    <NPagination
                        :page="roles.current_page"
                        :pages="roles.last_page"
                        prev-label="Назад"
                        next-label="Вперёд"
                        aria-label="Навигация по страницам"
                        @update:page="(p) => reload({ page: p })"
                    />
                </div>
            </template>
        </div>

        <ConfirmModal
            :open="del.open"
            :loading="del.loading"
            :message="`Удалить роль «${del.payload?.name}»? Пользователи потеряют связанные права.`"
            @confirm="confirmDelete"
            @cancel="del.close"
            @update:open="del.open = $event"
        />
    </AdminLayout>
</template>

<style scoped>
.page__toolbar {
    display: flex;
    align-items: center;
    gap: 12px;
}
.page__search {
    flex: 1;
    max-width: 360px;
}

.roles {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: var(--kpi-gap);
}
.roles__card {
    position: relative;
}
.roles__hint {
    margin: 0;
    font-size: 13px;
    color: var(--text-3);
}

.role {
    display: flex;
    flex-direction: column;
}
.role__head {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
}
.role__swatch {
    width: 11px;
    height: 11px;
    border-radius: 4px;
    flex: none;
}
.role__name {
    flex: 1;
    min-width: 0;
    margin: 0;
    font-size: calc(var(--fs) + 1.5px);
    font-weight: 800;
    letter-spacing: -0.01em;
    color: var(--text);
}
.role__name-link {
    display: block;
    width: 100%;
    color: inherit;
    text-decoration: none;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
/* Stretched link makes the entire card open its shareable detail URL. */
.role__name-link::after {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: var(--radius-lg);
}
.role__name-link:focus-visible {
    outline: none;
}
.role__name-link:focus-visible::after {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.role__actions {
    display: flex;
    align-items: center;
    gap: 2px;
    flex: none;
    /* Above the stretched link, otherwise the overlay would intercept the "Delete" click. */
    position: relative;
    z-index: 1;
    opacity: 0;
    transition: opacity 0.14s ease;
}
.roles__card:hover .role__actions,
.roles__card:focus-within .role__actions {
    opacity: 1;
}
.role__desc {
    font-size: calc(var(--fs) - 1px);
    line-height: 1.45;
    color: var(--text-3);
    min-height: 38px;
}
.role__footer {
    display: flex;
    gap: 20px;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--border);
}
.role__stat {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.role__num {
    font-size: calc(var(--fs) + 4px);
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--text);
}
.role__label {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--text-3);
}

/* Touch screens have no hover — show the actions permanently. */
@media (hover: none) {
    .role__actions {
        opacity: 1;
    }
}
</style>
