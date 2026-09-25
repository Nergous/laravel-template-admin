<script setup lang="ts">
import { computed, ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { NBadge, NButton, NCard } from "nergous-ui-vue";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import ConfirmModal from "@/admin/components/ConfirmModal.vue";
import { can } from "@/lib/can";
import { formatDateTime } from "@/lib/format";
import { listUrl } from "@/lib/listUrl";
import { permissionGroupLabels } from "@/admin/pages/Roles/permissionGroupLabels";

const props = defineProps({ role: { type: Object, required: true } });

// System roles cannot be deleted, matching the roles list.
const canDelete = computed(() => can("roles.delete") && !props.role.is_system);
const deleteOpen = ref(false);
const deleting = ref(false);

function confirmDelete() {
    deleting.value = true;
    router.delete(`/admin/roles/${props.role.id}`, {
        onFinish: () => {
            deleting.value = false;
            deleteOpen.value = false;
        },
    });
}

const permissionGroups = computed(() => {
    const groups: Record<string, string[]> = {};
    for (const name of props.role.permission_names ?? []) {
        const prefix = name.includes(".") ? name.split(".", 1)[0] : "other";
        (groups[prefix] ??= []).push(name);
    }
    return groups;
});

const copyState = ref("");
async function copyLink() {
    try {
        await navigator.clipboard.writeText(window.location.href);
        copyState.value = "Ссылка скопирована";
    } catch {
        copyState.value = "Не удалось скопировать";
    }
}
</script>

<template>
    <AdminLayout :title="role.name" subtitle="Карточка роли">
        <div class="page entity-page entity-page--wide">
            <div class="entity-page__bar">
                <Link :href="listUrl('/admin/roles')" class="entity-page__back"
                    >← К списку ролей</Link
                >
                <div class="entity-page__actions">
                    <NButton
                        variant="secondary"
                        icon="copy"
                        @click="copyLink"
                        >{{ copyState || "Скопировать ссылку" }}</NButton
                    >
                    <NButton
                        v-if="canDelete"
                        variant="danger"
                        icon="trash"
                        @click="deleteOpen = true"
                        >Удалить</NButton
                    >
                    <NButton
                        v-if="role.can_edit"
                        :as="Link"
                        :href="'/admin/roles/' + role.id + '/edit'"
                        variant="primary"
                        icon="edit"
                        >Редактировать</NButton
                    >
                </div>
            </div>

            <div class="entity-page__layout">
                <div class="entity-page__main">
                    <NCard padding="var(--kpi-pad)" class="entity-page__card">
                        <div class="entity-page__profile">
                            <div>
                                <div class="entity-page__badges">
                                    <h2>{{ role.name }}</h2>
                                    <NBadge size="sm">{{
                                        role.is_system
                                            ? "системная"
                                            : "кастомная"
                                    }}</NBadge>
                                </div>
                                <p>
                                    {{
                                        role.description || "Описание не задано"
                                    }}
                                </p>
                            </div>
                        </div>
                    </NCard>

                    <NCard padding="var(--kpi-pad)" class="entity-page__card">
                        <h2 class="entity-page__section-title">
                            Разрешения · {{ role.permission_names.length }}
                        </h2>
                        <div
                            v-if="role.permission_names.length"
                            class="entity-page__permission-groups"
                        >
                            <section
                                v-for="(names, group) in permissionGroups"
                                :key="group"
                            >
                                <h3>
                                    {{ permissionGroupLabels[group] ?? group }}
                                </h3>
                                <div class="entity-page__badges">
                                    <NBadge
                                        v-for="name in names"
                                        :key="name"
                                        pill
                                        >{{ name }}</NBadge
                                    >
                                </div>
                            </section>
                        </div>
                        <p v-else class="entity-page__muted">
                            Разрешений пока нет.
                        </p>
                    </NCard>
                </div>

                <aside class="entity-page__aside">
                    <NCard padding="var(--kpi-pad)" class="entity-page__card">
                        <h2 class="entity-page__section-title">Сведения</h2>
                        <dl class="entity-page__details">
                            <div>
                                <dt>ID</dt>
                                <dd>#{{ role.id }}</dd>
                            </div>
                            <div>
                                <dt>Пользователей</dt>
                                <dd>
                                    <Link
                                        v-if="can('users.view')"
                                        :href="
                                            '/admin/users?role=' +
                                            encodeURIComponent(role.name)
                                        "
                                        >{{ role.users_count }}</Link
                                    >
                                    <template v-else>{{
                                        role.users_count
                                    }}</template>
                                </dd>
                            </div>
                            <div>
                                <dt>Создана</dt>
                                <dd>{{ formatDateTime(role.created_at) }}</dd>
                            </div>
                            <div>
                                <dt>Обновлена</dt>
                                <dd>{{ formatDateTime(role.updated_at) }}</dd>
                            </div>
                            <div>
                                <dt>Создал</dt>
                                <dd>{{ role.creator_name ?? "—" }}</dd>
                            </div>
                            <div>
                                <dt>Изменил</dt>
                                <dd>{{ role.editor_name ?? "—" }}</dd>
                            </div>
                        </dl>
                    </NCard>
                </aside>
            </div>
        </div>

        <ConfirmModal
            :open="deleteOpen"
            :loading="deleting"
            :message="`Удалить роль «${role.name}»? Пользователи потеряют связанные права.`"
            @confirm="confirmDelete"
            @cancel="deleteOpen = false"
            @update:open="deleteOpen = $event"
        />
    </AdminLayout>
</template>
