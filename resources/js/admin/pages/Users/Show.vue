<script setup lang="ts">
import { ref } from "vue";
import { Link } from "@inertiajs/vue3";
import { NAvatar, NBadge, NButton, NCard } from "nergous-ui-vue";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import { can } from "@/lib/can";
import { formatDateTime } from "@/lib/format";
import { swatchColor } from "@/lib/swatch";

defineProps({ user: { type: Object, required: true } });

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
    <AdminLayout :title="user.name" subtitle="Карточка пользователя">
        <div class="page entity-page">
            <div class="entity-page__bar">
                <Link href="/admin/users" class="entity-page__back"
                    >← К списку пользователей</Link
                >
                <div class="entity-page__actions">
                    <NButton
                        variant="secondary"
                        icon="copy"
                        @click="copyLink"
                        >{{ copyState || "Скопировать ссылку" }}</NButton
                    >
                    <NButton
                        v-if="can('users.edit')"
                        :as="Link"
                        :href="`/admin/users/${user.id}/edit`"
                        variant="primary"
                        icon="edit"
                        >Редактировать</NButton
                    >
                </div>
            </div>

            <NCard padding="var(--kpi-pad)" class="entity-page__card">
                <div class="entity-page__profile">
                    <NAvatar :name="user.name" :size="52" />
                    <div>
                        <h2>{{ user.name }}</h2>
                        <p>{{ user.email }}</p>
                    </div>
                </div>
            </NCard>

            <NCard padding="var(--kpi-pad)" class="entity-page__card">
                <h2 class="entity-page__section-title">Доступ</h2>
                <div class="entity-page__badges">
                    <template v-if="user.roles?.length">
                        <template v-for="role in user.roles" :key="role.id">
                            <Link
                                v-if="can('roles.view')"
                                :href="'/admin/roles/' + role.id"
                                class="entity-page__badge-link"
                            >
                                <NBadge
                                    tone="neutral"
                                    pill
                                    :swatch="swatchColor(role.name)"
                                    >{{ role.name }}</NBadge
                                >
                            </Link>
                            <NBadge
                                v-else
                                tone="neutral"
                                pill
                                :swatch="swatchColor(role.name)"
                                >{{ role.name }}</NBadge
                            >
                        </template>
                    </template>
                    <span v-else>Роли не назначены</span>
                </div>
            </NCard>

            <NCard padding="var(--kpi-pad)" class="entity-page__card">
                <h2 class="entity-page__section-title">Сведения</h2>
                <dl class="entity-page__details">
                    <div>
                        <dt>ID</dt>
                        <dd>#{{ user.id }}</dd>
                    </div>
                    <div>
                        <dt>Email</dt>
                        <dd>{{ user.email }}</dd>
                    </div>
                    <div>
                        <dt>Добавлен</dt>
                        <dd>{{ formatDateTime(user.created_at) }}</dd>
                    </div>
                    <div>
                        <dt>Обновлён</dt>
                        <dd>{{ formatDateTime(user.updated_at) }}</dd>
                    </div>
                    <div>
                        <dt>Создал</dt>
                        <dd>{{ user.creator?.name ?? "—" }}</dd>
                    </div>
                    <div>
                        <dt>Изменил</dt>
                        <dd>{{ user.editor?.name ?? "—" }}</dd>
                    </div>
                </dl>
            </NCard>
        </div>
    </AdminLayout>
</template>
