<?php
$participantActionUrl = appUrl('responses', responseActionRedirectParams($formId, $selectedGroupId));
?>
<div class="modal fade" id="addParticipantModal" tabindex="-1" aria-labelledby="addParticipantModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <form method="post" action="<?= htmlspecialchars($participantActionUrl) ?>" id="manualParticipantForm" class="modal-content">
            <input type="hidden" name="response_action" value="add_participant">
            <div class="modal-header"><div><h2 class="modal-title h5 mb-1" id="addParticipantModalLabel">Adicionar participante</h2><p class="text-muted small mb-0"><?= htmlspecialchars($selectedForm['title']) ?></p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body">
                <?php if ((int) ($selectedForm['payment_enabled'] ?? 0) === 1): ?><div class="alert alert-info small">O participante será cadastrado sem escolher uma forma de pagamento e ficará pendente até ser aprovado. O lote atual será reservado normalmente.</div><?php endif; ?>
                <?php if ($responseOperationErrors): ?><div class="alert alert-danger"><?php foreach ($responseOperationErrors as $operationError): ?><div><?= htmlspecialchars($operationError) ?></div><?php endforeach; ?></div><?php endif; ?>
                <div class="row g-3">
                    <?php foreach ($fields as $field): ?>
                        <?php
                        $fieldId = (int) $field['id'];
                        $inputId = 'manual_participant_' . $fieldId;
                        $value = manualParticipantValue($field);
                        $options = manualParticipantOptions($field);
                        $width = in_array((int) ($field['width'] ?? 12), [12, 6, 4], true) ? (int) $field['width'] : 12;
                        $conditional = (int) ($field['conditional_enabled'] ?? 0) === 1;
                        $readonly = (int) ($field['is_readonly'] ?? 0) === 1;
                        $required = (int) ($field['is_required'] ?? 0) === 1;
                        ?>
                        <div class="col-md-<?= $width ?> manual-participant-field" data-manual-field="<?= $fieldId ?>" data-conditional="<?= $conditional ? '1' : '0' ?>" data-reference="<?= (int) ($field['conditional_field_id'] ?? 0) ?>" data-operator="<?= htmlspecialchars((string) ($field['conditional_operator'] ?? 'equals')) ?>" data-expected="<?= htmlspecialchars((string) ($field['conditional_value'] ?? '')) ?>" data-required="<?= $required ? '1' : '0' ?>" <?= $conditional ? 'hidden' : '' ?>>
                            <label class="form-label" for="<?= $inputId ?>"><?= htmlspecialchars($field['label']) ?><?= $required ? ' *' : '' ?></label>
                            <?php if (in_array($field['type'], ['text', 'email', 'phone', 'number', 'date'], true)): ?>
                                <?php $isDateInput = $field['type'] === 'date'; $inputType = $field['type'] === 'phone' ? 'tel' : ($isDateInput ? 'text' : $field['type']); $inputPlaceholder = $isDateInput && empty($field['placeholder']) ? 'DD/MM/AAAA' : (string) ($field['placeholder'] ?? ''); ?>
                                <input class="form-control" type="<?= htmlspecialchars($inputType) ?>" id="<?= $inputId ?>" name="participant[<?= $fieldId ?>]" value="<?= htmlspecialchars((string) $value) ?>" placeholder="<?= htmlspecialchars($inputPlaceholder) ?>" <?= $isDateInput ? 'data-date-input inputmode="numeric" maxlength="10" autocomplete="bday"' : '' ?> <?= $field['type'] === 'number' ? 'inputmode="decimal"' : '' ?> <?= $readonly ? 'readonly' : '' ?> <?= $required && !$conditional ? 'required' : '' ?>>
                            <?php elseif ($field['type'] === 'textarea'): ?>
                                <textarea class="form-control" id="<?= $inputId ?>" name="participant[<?= $fieldId ?>]" rows="3" placeholder="<?= htmlspecialchars((string) ($field['placeholder'] ?? '')) ?>" <?= $readonly ? 'readonly' : '' ?> <?= $required && !$conditional ? 'required' : '' ?>><?= htmlspecialchars((string) $value) ?></textarea>
                            <?php elseif ($field['type'] === 'select'): ?>
                                <select class="form-select" id="<?= $inputId ?>" name="participant[<?= $fieldId ?>]" <?= $readonly ? 'disabled' : '' ?> <?= $required && !$conditional ? 'required' : '' ?>><option value="">Selecione</option><?php foreach ($options as $option): ?><option value="<?= htmlspecialchars($option) ?>" <?= (string) $value === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option><?php endforeach; ?></select>
                                <?php if ($readonly): ?><input type="hidden" name="participant[<?= $fieldId ?>]" value="<?= htmlspecialchars((string) $value) ?>"><?php endif; ?>
                            <?php elseif (in_array($field['type'], ['radio', 'checkbox'], true)): ?>
                                <div class="manual-choice-group" id="<?= $inputId ?>"><?php foreach ($options as $optionIndex => $option): ?><?php $choiceId = $inputId . '_' . $optionIndex; $checked = $field['type'] === 'checkbox' ? in_array($option, (array) $value, true) : (string) $value === $option; ?><label class="form-check"><input class="form-check-input" type="<?= htmlspecialchars($field['type']) ?>" id="<?= $choiceId ?>" name="participant[<?= $fieldId ?>]<?= $field['type'] === 'checkbox' ? '[]' : '' ?>" value="<?= htmlspecialchars($option) ?>" <?= $checked ? 'checked' : '' ?> <?= $readonly ? 'disabled' : '' ?>> <span class="form-check-label"><?= htmlspecialchars($option) ?></span></label><?php endforeach; ?></div>
                            <?php endif; ?>
                            <?php if (!empty($field['help_text'])): ?><div class="form-text"><?= htmlspecialchars($field['help_text']) ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Adicionar participante</button></div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded',()=>{
    const modalElement=document.getElementById('addParticipantModal');
    const wrappers=Array.from(modalElement?.querySelectorAll('[data-manual-field]')||[]);
    const byId=new Map(wrappers.map(wrapper=>[wrapper.dataset.manualField,wrapper]));
    const normalize=value=>String(value??'').trim().toLocaleLowerCase('pt-BR');
    const values=wrapper=>{const controls=Array.from(wrapper.querySelectorAll('input:not([type=hidden]),select,textarea')).filter(control=>!control.disabled);const choices=controls.filter(control=>control.type==='radio'||control.type==='checkbox');if(choices.length)return choices.filter(control=>control.checked).map(control=>normalize(control.value));return controls[0]?[normalize(controls[0].value)]:[]};
    const matches=wrapper=>{if(wrapper.dataset.conditional!=='1')return true;const reference=byId.get(wrapper.dataset.reference);if(!reference||reference.hidden)return false;const actual=values(reference);const expected=normalize(wrapper.dataset.expected);const filled=actual.some(value=>value!=='');const equals=actual.includes(expected);const contains=expected!==''&&actual.some(value=>value.includes(expected));return {equals,not_equals:!equals,contains,not_contains:!contains,filled,empty:!filled}[wrapper.dataset.operator]??false};
    const update=()=>wrappers.forEach(wrapper=>{const show=matches(wrapper);wrapper.hidden=!show;wrapper.querySelectorAll('input,select,textarea').forEach(control=>{if(control.type==='hidden')return;control.disabled=!show||control.dataset.originalDisabled==='1';control.required=show&&wrapper.dataset.required==='1'&&!['checkbox'].includes(control.type)&&(control.type!=='radio'||control===wrapper.querySelector('input[type=radio]'));});});
    wrappers.forEach(wrapper=>wrapper.querySelectorAll('input,select,textarea').forEach(control=>control.dataset.originalDisabled=control.disabled?'1':'0'));
    modalElement?.addEventListener('input',update);modalElement?.addEventListener('change',update);update();
    modalElement?.querySelectorAll('[data-date-input]').forEach(input=>{
        const mask=()=>{let value=input.value.trim();const canonical=value.match(/^(\d{4})-(\d{2})-(\d{2})$/);if(canonical)value=canonical[3]+canonical[2]+canonical[1];const digits=value.replace(/\D/g,'').slice(0,8);input.value=digits.replace(/^(\d{2})(\d)/,'$1/$2').replace(/^(\d{2}\/\d{2})(\d)/,'$1/$2');input.setCustomValidity('');};
        const validate=()=>{if(input.value===''){input.setCustomValidity('');return;}const match=input.value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);if(!match){input.setCustomValidity('Informe a data no formato DD/MM/AAAA.');return;}const day=Number(match[1]),month=Number(match[2]),year=Number(match[3]),date=new Date(year,month-1,day);const valid=year>=1900&&year<=2100&&date.getFullYear()===year&&date.getMonth()===month-1&&date.getDate()===day;input.setCustomValidity(valid?'':'Informe uma data válida no formato DD/MM/AAAA.');};
        input.addEventListener('input',mask);input.addEventListener('blur',validate);mask();
    });
    modalElement?.querySelectorAll('input[type="number"]').forEach(input=>input.addEventListener('wheel',event=>{event.preventDefault();input.blur();},{passive:false}));
    const participantForm=document.getElementById('manualParticipantForm');
    const participantSubmit=participantForm?.querySelector('button[type="submit"]');
    participantForm?.addEventListener('submit',event=>{participantForm.querySelectorAll('[data-date-input]').forEach(input=>input.dispatchEvent(new Event('blur')));if(!participantForm.checkValidity()){event.preventDefault();participantForm.reportValidity();return;}if(participantSubmit){participantSubmit.disabled=true;participantSubmit.textContent='Adicionando...';participantSubmit.setAttribute('aria-busy','true');}});
    <?php if ($openParticipantModal): ?>bootstrap.Modal.getOrCreateInstance(modalElement).show();<?php endif; ?>
});
</script>
