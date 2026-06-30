<script setup>
import { useForm } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import { NCard } from "@/lib/nergous-cit";
import RoleForm from "@/admin/pages/Roles/Partials/Form.vue";

const props = defineProps({
    role: { type: Object, required: true },
    allPermissions: { type: Object, required: true },
    assigned: { type: Array, default: () => [] },
    // { created_by, updated_by, created_at, updated_at } — для панели «Сведения».
    meta: { type: Object, default: null },
});
const form = useForm({
    name: props.role.name,
    description: props.role.description ?? "",
    permissions: [...props.assigned],
});
function submit() {
    form.put(`/admin/roles/${props.role.id}`);
}
</script>

<template>
    <AdminLayout title="Редактирование роли" :subtitle="role.name">
        <div class="page">
            <NCard>
                <RoleForm
                    :form="form"
                    :all-permissions="allPermissions"
                    :meta="meta"
                    submit-label="Сохранить"
                    @submit="submit"
                />
            </NCard>
        </div>
    </AdminLayout>
</template>
