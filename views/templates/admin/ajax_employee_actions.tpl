{* Ajax render: employee actions list (same markup as configure.tpl) *}
{if $ajax_items}
  <div class="baal-session-actions">
    {foreach from=$ajax_items item=log name=li}
      {if $smarty.foreach.li.iteration <= 300}
        <div class="baal-session-action">
          <span class="baal-badge
{if isset($log.action_badge)}
  {$log.action_badge}
{elseif $log.is_status_change}
  baal-b-status
{elseif $log.action=='ADD'}
  baal-b-add
{elseif $log.action=='UPDATE'}
  baal-b-update
{elseif $log.action=='DELETE'}
  baal-b-delete
{else}
  baal-b-other
{/if}">
            <i class="{$log.action_icon|escape:'html':'UTF-8'}"></i>
            {$log.action_label|escape:'html':'UTF-8'}
          </span>

          <span class="baal-badge baal-b-time" style="margin-left:8px;" data-toggle="tooltip" title="Дата и час">
            <i class="icon-calendar"></i> {$log.created_at_fmt|escape:'html':'UTF-8'}
          </span>

          <span class="baal-badge baal-b-muted" style="margin-left:8px;" data-toggle="tooltip" title="Тип обект и ID">
            <i class="icon-tags"></i> {$log.object_type_label|escape:'html':'UTF-8'}{if $log.object_id} #{$log.object_id|escape:'html':'UTF-8'}{/if}
          </span>

          {if $log.details}
            <div style="margin-top:6px;">
              <span class="baal-badge baal-b-primary" data-toggle="tooltip" title="Детайли">
                <i class="icon-info"></i> {$log.details|escape:'html':'UTF-8'}
              </span>
            </div>
          {/if}

          {if $log.changes && count($log.changes) > 0}
            <div class="baal-change-details">
              <div class="baal-change-title">{l s='Какво е променено' mod='bestautoautologhistory'}:</div>
              <ul>
                {foreach from=$log.changes item=ch name=cl}
                  {if $smarty.foreach.cl.iteration <= 4}
                    <li><strong>{$ch.field|escape:'html':'UTF-8'}:</strong> <span class="baal-old">{$ch.old|default:'—'|escape:'html':'UTF-8'}</span> → <span class="baal-new">{$ch.new|default:'—'|escape:'html':'UTF-8'}</span></li>
                  {/if}
                {/foreach}
              </ul>
              {if count($log.changes) > 4}
                <div class="baal-change-more text-muted">+ {count($log.changes)-4} {l s='още промени' mod='bestautoautologhistory'}</div>
              {/if}
            </div>
          {/if}

          {if $log.events_count > 1}
            <button type="button" class="btn btn-default btn-xs" data-toggle="collapse" data-target="#evg_ajax_{$log.id_log|escape:'html':'UTF-8'}">
              <i class="icon-list"></i> {l s='Виж история' mod='bestautoautologhistory'} ({$log.events_count|escape:'html':'UTF-8'})
            </button>
            <div id="evg_ajax_{$log.id_log|escape:'html':'UTF-8'}" class="collapse" style="margin-top:8px;">
              <ul class="baal-changes-list">
                {foreach from=$log.events item=ev}
                  <li>
                    <strong>{$ev.created_at_fmt|escape:'html':'UTF-8'}:</strong>
                    <span class="baal-badge baal-b-other"><i class="{$ev.action_icon|escape:'html':'UTF-8'}"></i> {$ev.action_label|escape:'html':'UTF-8'}</span>
                    {if $ev.details}
                      <div style="margin-top:6px;">
                        <span class="baal-badge baal-b-primary" data-toggle="tooltip" title="Детайли">
                          <i class="icon-info"></i> {$ev.details|escape:'html':'UTF-8'}
                        </span>
                      </div>
                    {/if}
                    {if $ev.changes}
                      <div class="baal-change-details" style="margin-top:6px;">
                        <div class="baal-change-title">{l s='Какво е променено' mod='bestautoautologhistory'}:</div>
                        <ul>
                          {foreach from=$ev.changes item=ch name=cel}
                            {if $smarty.foreach.cel.iteration <= 4}
                              <li><strong>{$ch.field|escape:'html':'UTF-8'}:</strong> <span class="baal-old">{$ch.old|default:'—'|escape:'html':'UTF-8'}</span> → <span class="baal-new">{$ch.new|default:'—'|escape:'html':'UTF-8'}</span></li>
                            {/if}
                          {/foreach}
                        </ul>
                        {if count($ev.changes) > 4}
                          <div class="baal-change-more text-muted">+ {count($ev.changes)-4} {l s='още промени' mod='bestautoautologhistory'}</div>
                        {/if}
                      </div>
                    {/if}
                  </li>
                {/foreach}
              </ul>
            </div>
          {/if}
        </div>
      {/if}
    {/foreach}
  </div>
{else}
  <div class="baal-muted">{l s='Няма действия по текущите филтри.' mod='bestautoautologhistory'}</div>
{/if}
