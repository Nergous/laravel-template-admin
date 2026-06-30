<script setup>
import { NInput, NTextarea, NCheckbox, NFormField } from "@/lib/nergous-cit";
import { formatDateTime } from "@/lib/format.js";

// Presentational role form for the drawer on the Index page. The save/cancel
// buttons live in the NDrawer footer (DrawerFooter), submit is on the parent page.
const props = defineProps({
    form: { type: Object, required: true }, // useForm({ name, description, permissions: [names] })
    allPermissions: { type: Object, required: true }, // { users:[{id,name}], media:[...], ... }
    // Role metadata — only on edit. { created_by, updated_by, created_at, updated_at }.
    meta: { type: Object, default: null },
});

const GROUP_LABELS = {
    users: "Пользователи",
    media: "Медиатека",
    roles: "Роли",
    permissions: "Разрешения",
    other: "Прочее",
};

function toggle(name, checked) {
    const set = new Set(props.form.permissions);
    if (checked) set.add(name);
    else set.delete(name);
    props.form.permissions = Array.from(set);
}
</script>

<template>
    <form class="rform" @submit.prevent>
        <NFormField label="Название роли" :error="form.errors.name" required>
            <NInput
                v-model="form.name"
                placeholder="например, editor"
                :error="!!form.errors.name"
            />
        </NFormField>

        <NFormField
            label="Описание"
            :error="form.errors.description"
            hint="Короткое пояснение, что разрешает роль"
        >
            <NTextarea
                v-model="form.description"
                :rows="2"
                placeholder="например, создание и редактирование контента"
                :error="!!form.errors.description"
            />
        </NFormField>

        <NFormField
            tag="div"
            label="Разрешения"
            label-id="rform-perms-label"
            :error="form.errors.permissions"
        >
            <div
                class="rform__matrix"
                role="group"
                aria-labelledby="rform-perms-label"
            >
                <div
                    v-for="(perms, group) in allPermissions"
                    :key="group"
                    class="rform__group"
                    role="group"
                    :aria-labelledby="`rform-group-${group}`"
                >
                    <div
                        :id="`rform-group-${group}`"
                        class="rform__group-title"
                    >
                        {{ GROUP_LABELS[group] ?? group }}
                    </div>
                    <div class="rform__perms">
                        <NCheckbox
                            v-for="p in perms"
                            :key="p.name"
                            :model-value="form.permissions.includes(p.name)"
                            @update:model-value="(v) => toggle(p.name, v)"
                            >{{ p.name }}</NCheckbox
                        >
                    </div>
                </div>
            </div>
        </NFormField>

        <!-- Edit: "Details" panel -->
        <div v-if="meta" class="rform__panel">
            <h2 class="rform__panel-title">Сведения</h2>
            <dl class="rform__panel-list">
                <div class="rform__panel-row">
                    <dt class="rform__panel-key">Создал</dt>
                    <dd class="rform__panel-val">
                        {{ meta.created_by ?? "—" }}
                    </dd>
                </div>
                <div class="rform__panel-row">
                    <dt class="rform__panel-key">Изменил</dt>
                    <dd class="rform__panel-val">
                        {{ meta.updated_by ?? "—" }}
                    </dd>
                </div>
                <div class="rform__panel-row">
                    <dt class="rform__panel-key">Создано</dt>
                    <dd class="rform__panel-val">
                        {{ formatDateTime(meta.created_at) }}
                    </dd>
                </div>
                <div class="rform__panel-row">
                    <dt class="rform__panel-key">Обновлено</dt>
                    <dd class="rform__panel-val">
                        {{ formatDateTime(meta.updated_at) }}
                    </dd>
                </div>
            </dl>
        </div>
    </form>
</template>

<style scoped>
.rform {
    display: flex;
    flex-direction: column;
    gap: 18px;
}
.rform__matrix {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
}
.rform__group {
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 14px;
    background: var(--surface-2);
}
.rform__group-title {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    color: var(--text-3);
    margin-bottom: 10px;
}
.rform__perms {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

/* --- "Details" panel (edit) --- */
.rform__panel {
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface-2);
}
.rform__panel-title {
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: var(--text-3);
    margin: 0 0 10px;
}
.rform__panel-list {
    margin: 0;
}
.rform__panel-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 13px;
    padding: 4px 0;
}
.rform__panel-key {
    color: var(--text-3);
}
.rform__panel-val {
    margin: 0;
    font-weight: 600;
    color: var(--text);
}
</style>
