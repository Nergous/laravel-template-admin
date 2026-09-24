<script setup lang="ts">
import { NInput, NTextarea, NCheckbox, NFormField } from "nergous-ui-vue";
import { permissionGroupLabels } from "@/admin/pages/Roles/permissionGroupLabels";

// Presentational form shared by the create/edit role pages.
const props = defineProps({
    form: { type: Object, required: true },
    allPermissions: { type: Object, required: true }, // { users:[{id,name}], media:[...], ... }
    nameReadonly: { type: Boolean, default: false },
});
const emit = defineEmits(["submit"]);

function toggle(name: string, checked: boolean) {
    const set = new Set(props.form.permissions);
    if (checked) set.add(name);
    else set.delete(name);
    props.form.permissions = Array.from(set);
}
</script>

<template>
    <form class="rform" @submit.prevent="emit('submit')">
        <NFormField label="Название роли" :error="form.errors.name" required>
            <NInput
                v-model="form.name"
                :readonly="nameReadonly"
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
                        {{ permissionGroupLabels[group] ?? group }}
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
</style>
