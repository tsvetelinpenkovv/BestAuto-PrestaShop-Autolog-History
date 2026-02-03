{* Autolog History - PS 1.7.7.5 *}

<div class="baal-wrap" data-baal-token="{$admin_token|escape:'html':'UTF-8'}">
  <div class="baal-head">
    <div class="baal-brand">
      <img src="{$module_dir|escape:'html':'UTF-8'}logo.png" alt="BestAuto Autolog History" />
      <div>
        <h2 class="baal-title"><i class="icon-history"></i> {l s='BestAuto Autolog History' mod='bestautoautologhistory'}</h2>
        <div class="baal-sub">
          <span class="badge badge-info">v{$version|escape:'html':'UTF-8'}</span>
          <span style="margin-left:10px;" class="text-muted">{l s='Проследяване на действия в администрацията' mod='bestautoautologhistory'}</span>
        </div>
      </div>
    </div>

    <div>
      <a class="baal-link" href="{$csv_link|escape:'html':'UTF-8'}">
        <i class="icon-download"></i> {l s='Експорт CSV' mod='bestautoautologhistory'}
      </a>
    </div>
  </div>

  <div class="baal-tabs">
    <a class="baal-tab baal-team {if $tab != 'team'}baal-inactive{/if}" href="{$link_team|escape:'html':'UTF-8'}">
      <i class="icon-group"></i> {l s='Екипни действия' mod='bestautoautologhistory'}
    </a>
    <a class="baal-tab {if $tab != 'timeline'}baal-inactive{/if}" style="background:#0ea5e9" href="{$link_timeline|escape:'html':'UTF-8'}">
      <i class="icon-time"></i> {l s='Timeline' mod='bestautoautologhistory'}
    </a>
    <a class="baal-tab baal-git {if $tab != 'git'}baal-inactive{/if}" href="{$link_git|escape:'html':'UTF-8'}">
      <i class="icon-code-fork"></i> {l s='Git история' mod='bestautoautologhistory'}
    </a>
  </div>

  {if $tab == 'team'}
    {* KPI *}
    <div class="baal-kpi" style="margin-bottom:16px;">
      <div class="baal-card">
        <div class="num">{$kpi.total|escape:'html':'UTF-8'}</div>
        <div class="lbl"><i class="icon-bolt"></i> {l s='Общо действия (след филтри)' mod='bestautoautologhistory'}</div>
      </div>
      <div class="baal-card">
        <div class="num">{$kpi.unique|escape:'html':'UTF-8'}</div>
        <div class="lbl"><i class="icon-user"></i> {l s='Активни служители' mod='bestautoautologhistory'}</div>
      </div>
      <div class="baal-card">
        {if $kpi.top}
          <div class="num">{$kpi.top.employee|escape:'html':'UTF-8'}</div>
          <div class="lbl"><i class="icon-trophy"></i> {l s='Най-активен' mod='bestautoautologhistory'} ({$kpi.top.c|escape:'html':'UTF-8'} {l s='действия' mod='bestautoautologhistory'})</div>
        {else}
          <div class="num">—</div>
          <div class="lbl"><i class="icon-info"></i> {l s='Няма данни' mod='bestautoautologhistory'}</div>
        {/if}
      </div>
    
</div>

    {* Dashboard: реални работни сесии (последни 7 дни) *}
    <div class="baal-card baal-panel" style="margin-bottom:16px;">
      <div class="baal-card-top">
        <div>
          <div class="baal-emp" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
  <span><i class="icon-dashboard"></i> {l s='Dashboard по служител (последни 7 дни)' mod='bestautoautologhistory'}</span>

  {* избор на ден/период за dashboard (по дефолт: последни 7 дни общо) *}
  <form method="get" action="" class="baal-dash-filter" style="display:inline-flex; align-items:center; gap:8px; margin:0;">
    {* Always include controller + token to avoid "Невалиден ключ" on some hosts/browsers *}
    <input type="hidden" name="controller" value="AdminModules" />
    <input type="hidden" name="configure" value="bestautoautologhistory" />
    <input type="hidden" name="token" value="{$admin_token|escape:'html':'UTF-8'}" />
    <input type="hidden" name="baal_tab" value="team" />

    {* keep existing filters when changing dashboard range *}
    {if $filters.employee!=''}<input type="hidden" name="employee" value="{$filters.employee|escape:'htmlall':'UTF-8'}" />{/if}
    {if $filters.from!=''}<input type="hidden" name="from" value="{$filters.from|escape:'htmlall':'UTF-8'}" />{/if}
    {if $filters.to!=''}<input type="hidden" name="to" value="{$filters.to|escape:'htmlall':'UTF-8'}" />{/if}
    {if $filters.object_id>0}<input type="hidden" name="object_id" value="{$filters.object_id|escape:'htmlall':'UTF-8'}" />{/if}
    {if $filters.object_type!=''}<input type="hidden" name="object_type" value="{$filters.object_type|escape:'htmlall':'UTF-8'}" />{/if}
    {if $filters.only_status==1}<input type="hidden" name="only_status" value="1" />{/if}

    <span class="baal-muted" style="margin:0; font-weight:600;">{l s='Период:' mod='bestautoautologhistory'}</span>
    <select name="dash_range" class="form-control input-sm" style="height:32px; padding:4px 8px;">
      <option value="all" {if $dash_range=='all'}selected{/if}>{l s='Последни 7 дни (общо)' mod='bestautoautologhistory'}</option>
      <option value="0" {if $dash_range=='0'}selected{/if}>{l s='Днес' mod='bestautoautologhistory'}</option>
      <option value="1" {if $dash_range=='1'}selected{/if}>{l s='Вчера' mod='bestautoautologhistory'}</option>
      <option value="2" {if $dash_range=='2'}selected{/if}>{l s='Преди 2 дни' mod='bestautoautologhistory'}</option>
      <option value="3" {if $dash_range=='3'}selected{/if}>{l s='Преди 3 дни' mod='bestautoautologhistory'}</option>
      <option value="4" {if $dash_range=='4'}selected{/if}>{l s='Преди 4 дни' mod='bestautoautologhistory'}</option>
      <option value="5" {if $dash_range=='5'}selected{/if}>{l s='Преди 5 дни' mod='bestautoautologhistory'}</option>
      <option value="6" {if $dash_range=='6'}selected{/if}>{l s='Преди 6 дни' mod='bestautoautologhistory'}</option>
      <option value="7" {if $dash_range=='7'}selected{/if}>{l s='Преди 7 дни' mod='bestautoautologhistory'}</option>
          </select>
    <button type="submit" class="btn btn-default btn-sm baal-btn" style="height:32px; padding:4px 10px;">
      <i class="icon-refresh"></i> {l s='Покажи' mod='bestautoautologhistory'}
    </button>
  </form>
</div>
          <div class="baal-muted">{l s='Сесии, активност и действия.' mod='bestautoautologhistory'}</div>
        </div>
      </div>

      {if $dashboard && $dashboard|count > 0}
        <div class="baal-dashboard-grid">
          {foreach from=$dashboard item=d}
            <div class="baal-dash-card">
              <div class="baal-dash-name"><i class="icon-user"></i> {$d.employee|escape:'html':'UTF-8'}</div>
              <div class="baal-dash-row">
                <span class="baal-pill baal-pill-blue"><i class="icon-signin"></i> {$d.sessions|escape:'html':'UTF-8'} {l s='сесии' mod='bestautoautologhistory'}</span>
                <span class="baal-pill baal-pill-green"><i class="icon-bolt"></i> {$d.actions|escape:'html':'UTF-8'} {l s='действия' mod='bestautoautologhistory'}</span>
              </div>
              <div class="baal-dash-row">
                <span class="baal-pill baal-pill-gray"><i class="icon-time"></i> {$d.total_hm|escape:'html':'UTF-8'}</span>
                <span class="baal-muted">{l s='Последна активност:' mod='bestautoautologhistory'} {$d.last_activity|escape:'html':'UTF-8'}</span>
              </div>
            </div>
          {/foreach}
        </div>

        {* подробен списък със сесии за избрания период (за да се вижда реално филтрирането) *}
        {if $dash_sessions && $dash_sessions|count > 0}
          <div style="margin-top:14px;">
            <button type="button" class="btn btn-default btn-sm baal-btn" data-toggle="collapse" data-target="#baalSessionsTable">
              <i class="icon-list"></i> {l s='Покажи сесии (избрания период)' mod='bestautoautologhistory'} ({$dash_sessions|count|escape:'html':'UTF-8'})
            </button>
          </div>
          <div id="baalSessionsTable" class="collapse" style="margin-top:10px;">
            <div class="well well-sm" style="border-radius:12px; background:#fff;">
              <table class="table table-condensed table-bordered" style="margin-bottom:0; background:#fff;">
                <thead>
                  <tr>
                    <th style="width:20%">{l s='Вход' mod='bestautoautologhistory'}</th>
                    <th style="width:20%">{l s='Последна активност' mod='bestautoautologhistory'}</th>
                    <th style="width:20%">{l s='Изход' mod='bestautoautologhistory'}</th>
                    <th style="width:10%">{l s='Време' mod='bestautoautologhistory'}</th>
                    <th style="width:10%">{l s='Действия' mod='bestautoautologhistory'}</th>
                    <th>{l s='Служител' mod='bestautoautologhistory'}</th>
                    <th style="width:14%">IP</th>
                  </tr>
                </thead>
                <tbody>
                  {foreach from=$dash_sessions item=s}
                    <tr>
                      <td>{$s.login_at|escape:'html':'UTF-8'}</td>
                      <td>{$s.last_activity|escape:'html':'UTF-8'}</td>
                      <td>{$s.logout_at|escape:'html':'UTF-8'}</td>
                      <td>{$s.duration_hm|escape:'html':'UTF-8'}</td>
                      <td>{$s.actions|escape:'html':'UTF-8'}</td>
                      <td>{$s.employee|escape:'html':'UTF-8'} <span class="baal-muted">(ID: {$s.employee_id|escape:'html':'UTF-8'})</span></td>
                      <td>{$s.ip|escape:'html':'UTF-8'}</td>
                    </tr>
                  {/foreach}
                </tbody>
              </table>
              <div class="baal-muted" style="margin-top:8px;">{l s='Показват се до 300 сесии за избрания период.' mod='bestautoautologhistory'}</div>
            </div>
          </div>
        {/if}
      {else}
        <div class="alert alert-info baal-alert" style="margin:12px;"><i class="icon-info"></i> {l s='Няма сесии за избрания период.' mod='bestautoautologhistory'}</div>
      {/if}
    </div>

    {* Filters *}
    <div class="baal-card baal-panel" style="margin-bottom:16px;">
      <div class="baal-card-top">
        <div>
          <div class="baal-emp"><i class="icon-filter"></i> {l s='Филтри' mod='bestautoautologhistory'}</div>
          <div class="baal-muted">{l s='Филтрирай по служител, дата, тип и ID на обект' mod='bestautoautologhistory'}</div>
        </div>
      </div>

      <form method="get" action="" style="margin-top:12px;">
        {* Include controller+token explicitly to avoid "Невалиден ключ" on submit *}
        <input type="hidden" name="controller" value="AdminModules" />
        <input type="hidden" name="configure" value="bestautoautologhistory" />
        <input type="hidden" name="token" value="{$admin_token|escape:'html':'UTF-8'}" />
        <input type="hidden" name="baal_tab" value="team" />

        <div class="baal-filters">
          <div class="form-group" style="min-width:240px;">
            <label>{l s='Служител' mod='bestautoautologhistory'}</label>
            <select name="employee" class="form-control">
              <option value="">— {l s='Всички' mod='bestautoautologhistory'} —</option>
              {foreach from=$employees item=emp}
                <option value="{$emp.employee|escape:'html':'UTF-8'}" {if $filters.employee == $emp.employee}selected{/if}>{$emp.employee|escape:'html':'UTF-8'}</option>
              {/foreach}
            </select>
          </div>


          <div class="form-group" style="min-width:220px;">
            <label>{l s='Тип обект' mod='bestautoautologhistory'}</label>
            <select name="object_type" class="form-control">
              <option value="">— {l s='Всички' mod='bestautoautologhistory'} —</option>
              <option value="product" {if $filters.object_type == 'product'}selected{/if}>🧱 {l s='Продукт' mod='bestautoautologhistory'}</option>
              <option value="order" {if $filters.object_type == 'order'}selected{/if}>🛒 {l s='Поръчка' mod='bestautoautologhistory'}</option>
              <option value="customer" {if $filters.object_type == 'customer'}selected{/if}>👤 {l s='Клиент' mod='bestautoautologhistory'}</option>
              <option value="category" {if $filters.object_type == 'category'}selected{/if}>🏷️ {l s='Категория' mod='bestautoautologhistory'}</option>
              <option value="stock" {if $filters.object_type == 'stock'}selected{/if}>📦 {l s='Склад' mod='bestautoautologhistory'}</option>
              <option value="login" {if $filters.object_type == 'login'}selected{/if}>🔐 {l s='Вход' mod='bestautoautologhistory'}</option>
              <option value="logout" {if $filters.object_type == 'logout'}selected{/if}>🔓 {l s='Изход' mod='bestautoautologhistory'}</option>
              <option value="admin" {if $filters.object_type == 'admin'}selected{/if}>🖥️ {l s='Администрация' mod='bestautoautologhistory'}</option>
              <option value="system" {if $filters.object_type == 'system'}selected{/if}>⚙️ {l s='Система' mod='bestautoautologhistory'}</option>
            </select>
          </div>

          
          <div class="form-group" style="min-width:220px; display:flex; align-items:flex-end;">
            <label style="width:100%; margin-bottom:6px;">{l s='Филтър' mod='bestautoautologhistory'}</label>
            <div class="baal-check">
              <label style="margin:0; font-weight:600;">
                <input type="checkbox" name="only_status" value="1" {if $filters.only_status==1}checked{/if} />
                {l s='Само статуси' mod='bestautoautologhistory'}
              </label>
            </div>
          </div>
<div class="form-group">
            <button type="submit" class="btn btn-primary baal-btn"><i class="icon-search"></i> {l s='Търси' mod='bestautoautologhistory'}</button>
          </div>

          <div class="form-group">
            <a class="btn btn-default baal-btn" href="{$link_team|escape:'html':'UTF-8'}"><i class="icon-undo"></i> {l s='Изчисти' mod='bestautoautologhistory'}</a>
          </div>
        </div>
      </form>

      {* Manual cleanup by period *}
      <div style="margin-top:14px;">
        <button type="button" class="btn btn-default baal-btn" data-toggle="collapse" data-target="#baalClean">
          <i class="icon-trash"></i> {l s='Ръчно изчистване по период' mod='bestautoautologhistory'}
        </button>
      </div>
      <div id="baalClean" class="collapse" style="margin-top:12px;">
        <div class="well well-sm" style="border-radius:12px;">
          <form method="post" action="">
            <input type="hidden" name="controller" value="AdminModules" />
            <input type="hidden" name="configure" value="bestautoautologhistory" />
            <input type="hidden" name="token" value="{$admin_token|escape:'html':'UTF-8'}" />
            <input type="hidden" name="baal_tab" value="team" />

            <div class="baal-filters">
              <div class="form-group">
                <label>{l s='От дата' mod='bestautoautologhistory'}</label>
                <input type="date" name="BAAL_CLEAN_FROM" class="form-control" required />
              </div>
              <div class="form-group">
                <label>{l s='До дата' mod='bestautoautologhistory'}</label>
                <input type="date" name="BAAL_CLEAN_TO" class="form-control" required />
              </div>
              <div class="form-group" style="min-width:220px;">
                <label>{l s='Тип обект (по желание)' mod='bestautoautologhistory'}</label>
                <select name="BAAL_CLEAN_TYPE" class="form-control">
                  <option value="">— {l s='Всички' mod='bestautoautologhistory'} —</option>
                  <option value="product">{l s='Продукт' mod='bestautoautologhistory'}</option>
                  <option value="order">{l s='Поръчка' mod='bestautoautologhistory'}</option>
                  <option value="customer">{l s='Клиент' mod='bestautoautologhistory'}</option>
                  <option value="category">{l s='Категория' mod='bestautoautologhistory'}</option>
                  <option value="stock">{l s='Склад' mod='bestautoautologhistory'}</option>
                  <option value="admin">{l s='Администрация' mod='bestautoautologhistory'}</option>
                  <option value="system">{l s='Система' mod='bestautoautologhistory'}</option>
                  <option value="login">{l s='Вход' mod='bestautoautologhistory'}</option>
                  <option value="logout">{l s='Изход' mod='bestautoautologhistory'}</option>
                </select>
              </div>
              <div class="form-group">
                <button type="submit" name="submitBAALCleanLogs" class="btn btn-danger baal-btn">
                  <i class="icon-trash"></i> {l s='Изчисти логове' mod='bestautoautologhistory'}
                </button>
              </div>
            </div>

            {if $clean_err}
              <div class="alert alert-danger baal-alert" style="margin-top:10px;">
                <i class="icon-exclamation-triangle"></i> {$clean_err|escape:'html':'UTF-8'}
              </div>
            {/if}
            {if $clean_msg}
              <div class="alert alert-success baal-alert" style="margin-top:10px;">
                <i class="icon-check"></i> {$clean_msg|escape:'html':'UTF-8'}
              </div>
            {/if}
          </form>
        </div>
      </div>
    </div>

    {* Statistics *}
    <div class="baal-card baal-panel" style="margin-bottom:16px;">
      <div class="baal-card-top">
        <div>
          <div class="baal-emp"><i class="icon-bar-chart"></i> {l s='Статистики' mod='bestautoautologhistory'}</div>
          <div class="baal-muted">{l s='Бърз поглед върху активността (по текущите филтри)' mod='bestautoautologhistory'}</div>
        </div>
      </div>

      <div class="row" style="margin-top:12px;">
        <div class="col-md-4">
          <div class="baal-emp" style="margin-bottom:6px;"><i class="icon-group"></i> {l s='Топ служители' mod='bestautoautologhistory'}</div>
          <div class="table-responsive">
            <table class="table table-condensed table-striped" style="margin-bottom:0;">
              <thead><tr><th>{l s='Служител' mod='bestautoautologhistory'}</th><th style="width:80px;">{l s='Действия' mod='bestautoautologhistory'}</th></tr></thead>
              <tbody>
                {foreach from=$stats item=s}
                    <tr><td>{$s.employee|escape:'html':'UTF-8'}</td><td><strong>{$s.c|escape:'html':'UTF-8'}</strong></td></tr>
                {/foreach}
              </tbody>
            </table>
          </div>
          <div class="baal-pager baal-pager-top">
            {if $top_total_pages > 1}
              {for $p=1 to $top_total_pages}
                <a class="baal-page {if $p == $top_page}active{/if}" href="{$link_team|escape:'html':'UTF-8'}&top_page={$p}{if $fields_page}&fields_page={$fields_page}{/if}{if $filters.employee != ''}&employee={$filters.employee|escape:'url'}{/if}{if $filters.from != ''}&from={$filters.from|escape:'url'}{/if}{if $filters.to != ''}&to={$filters.to|escape:'url'}{/if}{if $filters.object_id}&object_id={$filters.object_id}{/if}{if $filters.object_type != ''}&object_type={$filters.object_type|escape:'url'}{/if}">{$p}</a>
              {/for}
            {/if}
          </div>
        </div>

        <div class="col-md-4">
          <div class="baal-emp" style="margin-bottom:6px;"><i class="icon-tags"></i> {l s='По тип обект' mod='bestautoautologhistory'}</div>
          <div class="table-responsive">
            <table class="table table-condensed table-striped" style="margin-bottom:0;">
              <thead><tr><th>{l s='Тип' mod='bestautoautologhistory'}</th><th style="width:80px;">{l s='Брой' mod='bestautoautologhistory'}</th></tr></thead>
              <tbody>
                {foreach from=$stats_by_type item=s}
                  <tr><td>{$s.object_type_label|default:$s.object_type|escape:'html':'UTF-8'}</td><td><strong>{$s.c|escape:'html':'UTF-8'}</strong></td></tr>
                {/foreach}
              </tbody>
            </table>
          </div>
        </div>

        <div class="col-md-4">
          <div class="baal-emp" style="margin-bottom:6px;"><i class="icon-calendar"></i> {l s='Последни 14 дни' mod='bestautoautologhistory'}</div>
          <div class="table-responsive">
            <table class="table table-condensed table-striped" style="margin-bottom:0;">
              <thead><tr><th>{l s='Дата' mod='bestautoautologhistory'}</th><th style="width:80px;">{l s='Брой' mod='bestautoautologhistory'}</th></tr></thead>
              <tbody>
                {foreach from=$stats_by_day item=s}
                  <tr><td>{$s.last_dt_fmt|default:$s.d|escape:'html':'UTF-8'}</td><td><strong>{$s.c|escape:'html':'UTF-8'}</strong></td></tr>
                {/foreach}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {* Heatmap by hour *}
      <div class="row" style="margin-top:16px;">
        <div class="col-md-12">
          <div class="baal-emp" style="margin-bottom:6px;"><i class="icon-clock-o"></i> {l s='Heatmap по часове (последни 14 дни)' mod='bestautoautologhistory'}</div>
          <div class="baal-heatmap">
            {foreach from=$heatmap_by_hour item=h}
              <div class="baal-heatcell baal-heat-{$h.lvl|escape:'html':'UTF-8'}" title="{$h.h|escape:'html':'UTF-8'}:00 - {$h.c|escape:'html':'UTF-8'}">
                <div class="hh">{$h.h|escape:'html':'UTF-8'}</div>
                <div class="cc">{$h.c|escape:'html':'UTF-8'}</div>
              </div>
            {/foreach}
          </div>
          <div class="baal-muted" style="margin-top:6px;">
            {l s='Колкото по-тъмен е квадратът, толкова повече действия има в този час (според текущите филтри).' mod='bestautoautologhistory'}
          </div>
        </div>
      </div>

      {* Most changed fields *}
      <div class="row" style="margin-top:16px;">
        <div class="col-md-12">
          <div class="baal-emp" style="margin-bottom:6px;"><i class="icon-list-ol"></i> {l s='Най-често променяни полета (последни 30 дни)' mod='bestautoautologhistory'}</div>
          <div class="table-responsive">
            <table class="table table-condensed table-striped" style="margin-bottom:0;">
              <thead><tr><th>{l s='Поле' mod='bestautoautologhistory'}</th><th style="width:90px;">{l s='Брой' mod='bestautoautologhistory'}</th></tr></thead>
              <tbody>
                {if $top_changed_fields}
                  {foreach from=$top_changed_fields item=tf}
                    <tr><td>{$tf.field|escape:'html':'UTF-8'}</td><td><strong>{$tf.c|escape:'html':'UTF-8'}</strong></td></tr>
                  {/foreach}
                {else}
                  <tr><td colspan="2" class="text-muted">{l s='Няма данни.' mod='bestautoautologhistory'}</td></tr>
                {/if}
              </tbody>
            </table>
          </div>
          <div class="baal-pager baal-pager-fields">
            {if $fields_total_pages > 1}
              {for $p=1 to $fields_total_pages}
                <a class="baal-page {if $p == $fields_page}active{/if}" href="{$link_team|escape:'html':'UTF-8'}&fields_page={$p}{if $top_page}&top_page={$top_page}{/if}{if $filters.employee != ''}&employee={$filters.employee|escape:'url'}{/if}{if $filters.from != ''}&from={$filters.from|escape:'url'}{/if}{if $filters.to != ''}&to={$filters.to|escape:'url'}{/if}{if $filters.object_id}&object_id={$filters.object_id}{/if}{if $filters.object_type != ''}&object_type={$filters.object_type|escape:'url'}{/if}">{$p}</a>
              {/for}
            {/if}
          </div>
        </div>
      </div>
    </div>

    {* Logs *}
    <div class="baal-card baal-panel">
      <div class="baal-card-top">
        <div>
          <div class="baal-emp"><i class="icon-list"></i> {l s='История на действията (по служител)' mod='bestautoautologhistory'}</div>
          <div class="baal-muted">{l s='Всеки служител е 1 ред. В падащото меню са всички негови действия според филтрите.' mod='bestautoautologhistory'}</div>
        </div>
        <div class="baal-badge baal-b-time"><i class="icon-info"></i> {$total_logs|escape:'html':'UTF-8'} {l s='служителя' mod='bestautoautologhistory'}</div>
      </div>

      {if $logs}
        <div class="table-responsive" style="margin-top:12px;">
          <table class="table table-striped table-hover baal-table">
            <thead>
              <tr>
                <th style="width:30%">{l s='Служител' mod='bestautoautologhistory'}</th>
                <th style="width:20%">{l s='Последна активност' mod='bestautoautologhistory'}</th>
                <th style="width:12%">{l s='Действия' mod='bestautoautologhistory'}</th>
                <th>{l s='Подробности' mod='bestautoautologhistory'}</th>
                <th style="width:52px"></th>
              </tr>
            </thead>
            <tbody>
              {foreach from=$logs item=g name=empgrp}
                <tr>
                  <td>
                    <div class="baal-emp"><i class="icon-user"></i> {$g.employee|escape:'html':'UTF-8'}</div>
                    <div class="baal-muted">ID: {$g.employee_id|escape:'html':'UTF-8'}</div>
                  </td>
                  <td>
                    <div class="baal-badge baal-b-time baal-last-at" data-employee-id="{$g.employee_id|escape:'html':'UTF-8'}"><i class="icon-calendar"></i> {$g.last_at_fmt|default:'—'|escape:'html':'UTF-8'}</div>
                  </td>
                  <td>
                    <span class="badge badge-info baal-actions-count" data-employee-id="{$g.employee_id|escape:'html':'UTF-8'}">{$g.actions_count|escape:'html':'UTF-8'}</span>
                  </td>
                  <td class="baal-muted">
                    {l s='Всички действия на служителя са в падащото меню.' mod='bestautoautologhistory'}
                  </td>
                  <td>
                    <button type="button" class="btn btn-default btn-sm" data-toggle="collapse" data-target="#emp_{$smarty.foreach.empgrp.iteration}">
                      <i class="icon-chevron-down"></i>
                    </button>
                  </td>
                </tr>

                <tr class="baal-session-row">
                  <td colspan="5" style="padding:0; border-top:0;">
                    <div id="emp_{$smarty.foreach.empgrp.iteration}" class="collapse baal-emp-collapse" data-employee-id="{$g.employee_id|escape:'html':'UTF-8'}" style="padding:12px 16px;">
                      {if $g.items}
                        <div class="baal-emp" style="margin-bottom:8px;"><i class="icon-sitemap"></i> {l s='Действия на служителя' mod='bestautoautologhistory'}</div>

                        <div class="baal-session-actions baal-live-items" data-employee-id="{$g.employee_id|escape:'html':'UTF-8'}">
                          {foreach from=$g.items item=log name=li}
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
                                {if isset($log.summary) && $log.summary!=''}
                                  <div class="baal-summary">{$log.summary|escape:'html':'UTF-8'}</div>
                                {/if}

                                <span class="baal-badge baal-b-time" style="margin-left:8px;" data-toggle="tooltip" title="Дата и час">
                                  <i class="icon-calendar"></i> {$log.created_at_fmt|escape:'html':'UTF-8'}
                                </span>

                                <span class="baal-badge baal-b-muted" style="margin-left:8px;" data-toggle="tooltip" title="Тип обект и ID">
                                  <i class="icon-tags"></i> {$log.object_type_label|escape:'html':'UTF-8'}{if $log.object_id} #{$log.object_id|escape:'html':'UTF-8'}{/if}
                                </span>

                                {if $log.details}
                                  <div style="margin-top:6px;">
                                    <span class="baal-badge baal-text-badge {$ev.action_badge|escape:'html':'UTF-8'}" data-toggle="tooltip" title="Детайли">
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
                                  <button type="button" class="btn btn-default btn-xs" data-toggle="collapse" data-target="#evg_{$log.id_log|escape:'html':'UTF-8'}">
                                    <i class="icon-list"></i> {l s='Виж история' mod='bestautoautologhistory'} ({$log.events_count|escape:'html':'UTF-8'})
                                  </button>
                                  <div id="evg_{$log.id_log|escape:'html':'UTF-8'}" class="collapse" style="margin-top:8px;">
                                    <ul class="baal-changes-list">
                                      {foreach from=$log.events item=ev}
                                        <li>
                                          <strong>{$ev.created_at_fmt|escape:'html':'UTF-8'}:</strong>
                                          <span class="baal-badge baal-b-other"><i class="{$ev.action_icon|escape:'html':'UTF-8'}"></i> {$ev.action_label|escape:'html':'UTF-8'}</span>
                                          {if $ev.details}
                                            <div style="margin-top:6px;">
                                              <span class="baal-badge baal-text-badge {$ev.action_badge|escape:'html':'UTF-8'}" data-toggle="tooltip" title="Детайли">
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
                                {elseif $log.changes && count($log.changes) > 0}
                                  <button type="button" class="btn btn-default btn-xs" data-toggle="collapse" data-target="#chg_{$log.id_log|escape:'html':'UTF-8'}">
                                    <i class="icon-list"></i> {l s='Промени' mod='bestautoautologhistory'} ({count($log.changes)})
                                  </button>
                                  <div id="chg_{$log.id_log|escape:'html':'UTF-8'}" class="collapse" style="margin-top:8px;">
                                    <ul class="baal-changes-list">
                                      {foreach from=$log.changes item=ch}
                                        <li>
                                          <strong>{$ch.field|escape:'html':'UTF-8'}:</strong>
                                          <span class="text-danger"><del>{$ch.old|default:'—'|escape:'html':'UTF-8'}</del></span>
                                          → <span class="text-success"><strong>{$ch.new|default:'—'|escape:'html':'UTF-8'}</strong></span>
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
                        <div class="text-muted">{l s='Няма действия по тези филтри.' mod='bestautoautologhistory'}</div>
                      {/if}
                    </div>
                  </td>
                </tr>
              {/foreach}
            </tbody>
          </table>
        </div>

        {* Pagination *}
        {if $total_pages > 1}
          <div style="text-align:center; margin-top:14px;">
            <ul class="pagination">
              {if $current_page > 1}
                <li>
                  <a href="{$link_team|escape:'html':'UTF-8'}&page={$current_page-1|escape:'html':'UTF-8'}{if $filters.employee}&employee={$filters.employee|urlencode}{/if}{if $filters.from}&from={$filters.from|urlencode}{/if}{if $filters.to}&to={$filters.to|urlencode}{/if}{if $filters.object_id}&object_id={$filters.object_id|escape:'html':'UTF-8'}{/if}{if $filters.object_type}&object_type={$filters.object_type|urlencode}{/if}">
                    <i class="icon-chevron-left"></i>
                  </a>
                </li>
              {/if}

              {assign var=start_page value=max(1, $current_page - 3)}
              {assign var=end_page value=min($total_pages, $current_page + 3)}

              {section name=p start=$start_page loop=$end_page+1}
                {assign var=page_num value=$smarty.section.p.index}
                <li {if $page_num == $current_page}class="active"{/if}>
                  <a href="{$link_team|escape:'html':'UTF-8'}&page={$page_num|escape:'html':'UTF-8'}{if $filters.employee}&employee={$filters.employee|urlencode}{/if}{if $filters.from}&from={$filters.from|urlencode}{/if}{if $filters.to}&to={$filters.to|urlencode}{/if}{if $filters.object_id}&object_id={$filters.object_id|escape:'html':'UTF-8'}{/if}{if $filters.object_type}&object_type={$filters.object_type|urlencode}{/if}">
                    {$page_num|escape:'html':'UTF-8'}
                  </a>
                </li>
              {/section}

              {if $current_page < $total_pages}
                <li>
                  <a href="{$link_team|escape:'html':'UTF-8'}&page={$current_page+1|escape:'html':'UTF-8'}{if $filters.employee}&employee={$filters.employee|urlencode}{/if}{if $filters.from}&from={$filters.from|urlencode}{/if}{if $filters.to}&to={$filters.to|urlencode}{/if}{if $filters.object_id}&object_id={$filters.object_id|escape:'html':'UTF-8'}{/if}{if $filters.object_type}&object_type={$filters.object_type|urlencode}{/if}">
                    <i class="icon-chevron-right"></i>
                  </a>
                </li>
              {/if}
            </ul>
            <div class="baal-muted" style="margin-top:6px;">
              {l s='Страница' mod='bestautoautologhistory'} {$current_page|escape:'html':'UTF-8'} {l s='от' mod='bestautoautologhistory'} {$total_pages|escape:'html':'UTF-8'}
            </div>
          </div>
        {/if}
      {else}
        <div class="alert alert-info baal-alert" style="margin-top:12px;">
          <i class="icon-info"></i> {l s='Няма намерени записи' mod='bestautoautologhistory'}
        </div>
      {/if}
    </div>

  {elseif $tab == 'compare'}
    <div class="baal-card baal-panel" style="margin-bottom:16px;">
      <div class="baal-card-top">
        <div>
          <div class="baal-emp"><i class="icon-exchange"></i> {l s='Сравнение на служители' mod='bestautoautologhistory'}</div>
          <div class="baal-muted">{l s='Избери до 3 служителя и сравни активността им (по текущите филтри).' mod='bestautoautologhistory'}</div>
        </div>
      </div>

      <form method="get" action="{$link_team|escape:'html':'UTF-8'}" style="margin-top:12px;">
        <input type="hidden" name="baal_tab" value="compare" />

        <div class="baal-filters">
          <div class="form-group" style="min-width:320px;">
            <label>{l s='Служители (до 3)' mod='bestautoautologhistory'}</label>
            <select name="compare_employees[]" class="form-control" multiple size="6">
              {foreach from=$employees item=emp}
                <option value="{$emp.employee|escape:'html':'UTF-8'}" {if in_array($emp.employee, $compare)}selected{/if}>{$emp.employee|escape:'html':'UTF-8'}</option>
              {/foreach}
            </select>
          </div>

          <div class="form-group" style="align-self:flex-end;">
            <button type="submit" class="btn btn-primary baal-btn"><i class="icon-search"></i> {l s='Покажи' mod='bestautoautologhistory'}</button>
          </div>
        </div>
      </form>
    </div>

    {if $compare}
      <div class="row">
        <div class="col-md-6">
          <div class="baal-card baal-panel" style="margin-bottom:16px;">
            <div class="baal-card-top">
              <div>
                <div class="baal-emp"><i class="icon-tags"></i> {l s='По тип обект' mod='bestautoautologhistory'}</div>
                <div class="baal-muted">{l s='Сравнение по тип обект (сума от действията)' mod='bestautoautologhistory'}</div>
              </div>
            </div>

            <div class="table-responsive" style="margin-top:12px;">
              <table class="table table-condensed table-striped" style="margin-bottom:0;">
                <thead><tr><th>{l s='Служител' mod='bestautoautologhistory'}</th><th>{l s='Тип' mod='bestautoautologhistory'}</th><th style="width:90px;">{l s='Брой' mod='bestautoautologhistory'}</th></tr></thead>
                <tbody>
                  {foreach from=$compare item=ename}
                    {if isset($compare_by_type[$ename])}
                      {foreach from=$compare_by_type[$ename] item=r}
                        <tr><td>{$ename|escape:'html':'UTF-8'}</td><td>{$r.type|escape:'html':'UTF-8'}</td><td><strong>{$r.c|escape:'html':'UTF-8'}</strong></td></tr>
                      {/foreach}
                    {else}
                      <tr><td>{$ename|escape:'html':'UTF-8'}</td><td class="text-muted">—</td><td class="text-muted">0</td></tr>
                    {/if}
                  {/foreach}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-md-6">
          <div class="baal-card baal-panel" style="margin-bottom:16px;">
            <div class="baal-card-top">
              <div>
                <div class="baal-emp"><i class="icon-clock-o"></i> {l s='Heatmap по часове (последни 14 дни)' mod='bestautoautologhistory'}</div>
                <div class="baal-muted">{l s='Сравнение по час за избраните служители' mod='bestautoautologhistory'}</div>
              </div>
            </div>

            <div style="margin-top:12px;">
              {foreach from=$compare item=ename}
                <div class="baal-emp" style="margin:10px 0 6px 0;"><i class="icon-user"></i> {$ename|escape:'html':'UTF-8'}</div>
                <div class="baal-heatmap">
                  {for $h=0 to 23}
                    {assign var=c value=$compare_by_hour[$ename][$h]}
                    <div class="baal-heatcell baal-heat-0" title="{$h}:00 - {$c}">
                      <div class="hh">{$h}</div>
                      <div class="cc">{$c}</div>
                    </div>
                  {/for}
                </div>
              {/foreach}
              <div class="baal-muted" style="margin-top:8px;">{l s='Забележка: При сравнение квадратите не са нормализирани (показват реални бройки).' mod='bestautoautologhistory'}</div>
            </div>
          </div>
        </div>
      </div>
    {else}
      <div class="alert alert-info baal-alert"><i class="icon-info"></i> {l s='Избери поне един служител.' mod='bestautoautologhistory'}</div>
    {/if}


  {elseif $tab == 'timeline'}
    <div class="baal-card baal-panel">
      <div class="baal-card-top">
        <div>
          <div class="baal-emp"><i class="icon-time"></i> {l s='Timeline' mod='bestautoautologhistory'}</div>
          <div class="baal-muted">{l s='Последни действия (групирани при поръчки).' mod='bestautoautologhistory'}</div>
        </div>
        <form method="get" action="" class="baal-mini-filter">
          {* Include controller+token explicitly to avoid "Невалиден ключ за сигурност" when auto-submitting *}
          <input type="hidden" name="controller" value="AdminModules" />
          <input type="hidden" name="configure" value="bestautoautologhistory" />
          <input type="hidden" name="token" value="{$admin_token|escape:'html':'UTF-8'}" />
          <input type="hidden" name="baal_tab" value="timeline" />
          {if $filters.employee!=''}<input type="hidden" name="employee" value="{$filters.employee|escape:'html':'UTF-8'}" />{/if}
          {if $filters.from!=''}<input type="hidden" name="from" value="{$filters.from|escape:'html':'UTF-8'}" />{/if}
          {if $filters.to!=''}<input type="hidden" name="to" value="{$filters.to|escape:'html':'UTF-8'}" />{/if}
          {if $filters.object_id>0}<input type="hidden" name="object_id" value="{$filters.object_id|escape:'html':'UTF-8'}" />{/if}
          {if $filters.object_type!=''}<input type="hidden" name="object_type" value="{$filters.object_type|escape:'html':'UTF-8'}" />{/if}
          <label class="baal-mini-check">
            <input type="checkbox" name="only_status" value="1" {if $filters.only_status==1}checked{/if} onchange="this.form.submit();" />
            {l s='Само статуси' mod='bestautoautologhistory'}
          </label>
        </form>
      </div>

        <div>
          <div class="baal-emp"><i class="icon-time"></i> {l s='Timeline' mod='bestautoautologhistory'}</div>
          <div class="baal-muted">{l s='Последните събития (групирани поръчки са с падащо меню)' mod='bestautoautologhistory'}</div>
        </div>
        <div class="baal-badge baal-b-time"><i class="icon-list"></i> {count($timeline)} {l s='събития' mod='bestautoautologhistory'}</div>
      </div>

      {if $timeline}
        <ul class="baal-timeline" style="margin-top:16px;">
          {foreach from=$timeline item=item}
            <li class="baal-timeline-item">
              <div class="baal-timeline-dot {if $item.is_status_change}baal-dot-status{elseif $item.action=='ADD'}baal-dot-add{elseif $item.action=='UPDATE'}baal-dot-update{elseif $item.action=='DELETE'}baal-dot-delete{elseif $item.action=='INSTALL'}baal-dot-system{elseif $item.action=='POST'}baal-dot-post{elseif $item.action=='VIEW'}baal-dot-view{else}baal-dot-nav{/if}"><i class="{$item.action_icon|escape:'html':'UTF-8'}"></i></div>
              <div class="baal-timeline-card">
                <div class="baal-card-top">
                  <div>
                    <div class="baal-emp">
                      <i class="{$item.action_icon|escape:'html':'UTF-8'}"></i>
                      {$item.action_label|escape:'html':'UTF-8'}
                      <span class="baal-muted" style="margin-left:8px;">
                        <i class="icon-user"></i> {$item.employee|escape:'html':'UTF-8'}
                        <span class="baal-muted" style="margin-left:8px;">ID: {$item.employee_id|escape:'html':'UTF-8'}</span>
                      </span>
                    </div>
                    <div class="baal-muted" style="margin-top:4px;">
                      <i class="{$item.object_icon|escape:'html':'UTF-8'}"></i>
                      {if $item.object_type == 'product'}{l s='Продукт' mod='bestautoautologhistory'}
                      {elseif $item.object_type == 'order' || $item.object_type == 'order_status'}{l s='Поръчка' mod='bestautoautologhistory'}
                      {elseif $item.object_type == 'customer'}{l s='Клиент' mod='bestautoautologhistory'}
                      {elseif $item.object_type == 'category'}{l s='Категория' mod='bestautoautologhistory'}
                      {elseif $item.object_type == 'stock'}{l s='Склад' mod='bestautoautologhistory'}
                      {elseif $item.object_type == 'login'}{l s='Вход' mod='bestautoautologhistory'}
                      {elseif $item.object_type == 'logout'}{l s='Изход' mod='bestautoautologhistory'}
                      {elseif $item.object_type == 'admin'}{l s='Администрация' mod='bestautoautologhistory'}
                      {elseif $item.object_type == 'system'}{l s='Система' mod='bestautoautologhistory'}
                      {else}{$item.object_type|escape:'html':'UTF-8'}{/if}
                      {if $item.object_id} #{$item.object_id|escape:'html':'UTF-8'}{/if}
                      · <i class="icon-calendar"></i> {if $item.group_last_at_fmt}{$item.group_last_at_fmt|escape:'html':'UTF-8'}{else}{$item.created_at_fmt|escape:'html':'UTF-8'}{/if}
                      · <i class="icon-map-marker"></i> {$item.ip|escape:'html':'UTF-8'}
                    </div>
                  </div>
                  {if $item.events_count > 1}
                    <button type="button" class="btn btn-default btn-sm" data-toggle="collapse" data-target="#tl_{$item.id_log|escape:'html':'UTF-8'}">
                      <i class="icon-chevron-down"></i> {l s='История' mod='bestautoautologhistory'} ({$item.events_count|escape:'html':'UTF-8'})
                    </button>
                  {/if}
                </div>

                {if $item.details}
                  <div style="margin-top:10px;">
                    <span class="baal-badge baal-text-badge {$item.action_badge|escape:'html':'UTF-8'}">
                      <i class="icon-info"></i> {$item.details|escape:'html':'UTF-8'}
                    </span>
                  </div>
                {/if}

                {if $item.events_count > 1}
                  <div id="tl_{$item.id_log|escape:'html':'UTF-8'}" class="collapse" style="margin-top:12px;">
                    <div class="well well-sm">
                      <table class="table table-condensed table-bordered" style="margin-bottom:0; background:#fff;">
                        <thead>
                          <tr>
                            <th style="width:18%">{l s='Дата/час' mod='bestautoautologhistory'}</th>
                            <th style="width:14%">{l s='Действие' mod='bestautoautologhistory'}</th>
                            <th>{l s='Промени' mod='bestautoautologhistory'}</th>
                          </tr>
                        </thead>
                        <tbody>
                          {foreach from=$item.events item=ev}
                            <tr>
                              <td><small>{$ev.created_at_fmt|escape:'html':'UTF-8'}</small></td>
                              <td><span class="baal-badge baal-b-other"><i class="{$ev.action_icon|escape:'html':'UTF-8'}"></i> {$ev.action_label|escape:'html':'UTF-8'}</span></td>
                              <td>
                                {if $ev.details}
                                  <div style="margin-top:6px;">
                                    <span class="baal-badge baal-text-badge {$ev.action_badge|escape:'html':'UTF-8'}" data-toggle="tooltip" title="Детайли">
                                      <i class="icon-info"></i> {$ev.details|escape:'html':'UTF-8'}
                                    </span>
                                  </div>
                                {/if}
                                {if $ev.changes && count($ev.changes) > 0}
                                  <div class="baal-change-details" style="margin-top:6px;">
                                    <div class="baal-change-title">{l s='Какво е променено' mod='bestautoautologhistory'}:</div>
                                    <ul>
                                      {foreach from=$ev.changes item=ch name=cl}
                                        {if $smarty.foreach.cl.iteration <= 4}
                                          <li><strong>{$ch.field|escape:'html':'UTF-8'}:</strong> <span class="baal-old">{$ch.old|default:'—'|escape:'html':'UTF-8'}</span> → <span class="baal-new">{$ch.new|default:'—'|escape:'html':'UTF-8'}</span></li>
                                        {/if}
                                      {/foreach}
                                    </ul>
                                    {if count($ev.changes) > 4}
                                      <div class="baal-change-more text-muted">+ {count($ev.changes)-4} {l s='още промени' mod='bestautoautologhistory'}</div>
                                    {/if}
                                  </div>
                                {/if}
                              </td>
                            </tr>
                          {/foreach}
                        </tbody>
                      </table>
                    </div>
                  </div>
                {else}
                  {if $item.changes && count($item.changes) > 0}
                    <div class="baal-change-details" style="margin-top:10px;">
                      <div class="baal-change-title">{l s='Какво е променено' mod='bestautoautologhistory'}:</div>
                      <ul>
                        {foreach from=$item.changes item=ch name=clp}
                          {if $smarty.foreach.clp.iteration <= 4}
                            <li><strong>{$ch.field|escape:'html':'UTF-8'}:</strong> <span class="baal-old">{$ch.old|default:'—'|escape:'html':'UTF-8'}</span> → <span class="baal-new">{$ch.new|default:'—'|escape:'html':'UTF-8'}</span></li>
                          {/if}
                        {/foreach}
                      </ul>
                      {if count($item.changes) > 4}
                        <div class="baal-change-more text-muted">+ {count($item.changes)-4} {l s='още промени' mod='bestautoautologhistory'}</div>
                      {/if}
                    </div>
                  {/if}
                {/if}
              </div>
            </li>
          {/foreach}
        </ul>
      {else}
        <div class="alert alert-info baal-alert" style="margin-top:12px;">
          <i class="icon-info"></i> {l s='Няма намерени записи' mod='bestautoautologhistory'}
        </div>
      {/if}
    </div>

  {elseif $tab == 'git'}
    <div class="baal-grid">
      <div class="baal-card baal-panel">
        <div class="baal-card-top">
          <div>
            <div class="baal-emp"><i class="icon-cog"></i> {l s='Git настройки' mod='bestautoautologhistory'}</div>
            <div class="baal-muted">{l s='Синхронизация на последните комити от Git' mod='bestautoautologhistory'}</div>
            {if $git_branch || $git_head}
              <div class="baal-muted" style="margin-top:6px;">
                <span class="baal-badge baal-b-time"><i class="icon-code-fork"></i> {l s='Текущ' mod='bestautoautologhistory'}: {if $git_branch}<strong>{$git_branch|escape:'html':'UTF-8'}</strong>{/if}{if $git_head} · <code>{$git_head|escape:'html':'UTF-8'}</code>{/if}</span>
              </div>
            {/if}
          </div>
        </div>
      {if $gitStats && $gitStats|count>0}
        <div class="baal-dashboard-grid" style="padding-top:0;">
          {foreach from=$gitStats item=gs}
            <div class="baal-dash-card">
              <div class="baal-dash-name"><i class="icon-code"></i> {$gs.author|escape:'html':'UTF-8'}</div>
              <div class="baal-dash-row">
                <span class="baal-pill baal-pill-green"><i class="icon-bolt"></i> {$gs.c|escape:'html':'UTF-8'} commits</span>
              </div>
              <div class="baal-muted">Last: {$gs.last_at|escape:'html':'UTF-8'}</div>
            </div>
          {/foreach}
        </div>
      {/if}


        <form method="post" action="" style="margin-top:12px;">
          <input type="hidden" name="controller" value="AdminModules" />
          <input type="hidden" name="configure" value="bestautoautologhistory" />
          <input type="hidden" name="token" value="{$admin_token|escape:'html':'UTF-8'}" />
          <input type="hidden" name="baal_tab" value="git" />
          <div class="form-group">
            <label>{l s='Git път' mod='bestautoautologhistory'}</label>
            <input type="text" name="BAAL_GIT_PATH" class="form-control" value="{$git_path|escape:'html':'UTF-8'}" />
            <p class="help-block">{l s='Път до Git репозиторито (напр. /home/user/project)' mod='bestautoautologhistory'}</p>
          </div>
          <div class="form-group">
            <label>{l s='Брой комити' mod='bestautoautologhistory'}</label>
            <input type="number" name="BAAL_GIT_LIMIT" class="form-control" value="{$git_limit|escape:'html':'UTF-8'}" min="10" max="200" />
          </div>

          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="submit" name="submitBAALSettings" class="btn btn-primary baal-btn"><i class="icon-save"></i> {l s='Запази' mod='bestautoautologhistory'}</button>
            <button type="submit" name="submitBAALGitSync" class="btn btn-success baal-btn"><i class="icon-refresh"></i> {l s='Синхронизирай Git' mod='bestautoautologhistory'}</button>
          </div>
        </form>

        {if $git_error}
          <div class="alert alert-danger baal-alert" style="margin-top:12px;">
            <i class="icon-exclamation-triangle"></i> {$git_error|escape:'html':'UTF-8'}
          </div>
        {/if}
      </div>

      <div class="baal-card baal-panel">
        <div class="baal-card-top">
          <div>
            <div class="baal-emp"><i class="icon-code-fork"></i> {l s='Git история' mod='bestautoautologhistory'}</div>
            <div class="baal-muted">{l s='Показва синхронизираните комити' mod='bestautoautologhistory'}</div>
          </div>
          <div class="baal-badge baal-b-time"><i class="icon-list"></i> {count($commits)} {l s='комита' mod='bestautoautologhistory'}</div>
        </div>

        {if $commits}
          <div class="table-responsive" style="margin-top:12px;">
            <table class="table table-striped">
              <thead>
                <tr>
                  <th>{l s='Хеш' mod='bestautoautologhistory'}</th>
                  <th>{l s='Автор' mod='bestautoautologhistory'}</th>
                  <th>{l s='Дата' mod='bestautoautologhistory'}</th>
                  <th>{l s='Съобщение' mod='bestautoautologhistory'}</th>
                </tr>
              </thead>
              <tbody>
                {foreach from=$commits item=c}
                  <tr>
                    <td><code>{$c.commit_hash|escape:'html':'UTF-8'}</code></td>
                    <td><strong>{$c.author_name|escape:'html':'UTF-8'}</strong><br><small class="text-muted">{$c.author_email|escape:'html':'UTF-8'}</small></td>
                    <td><small>{$c.commit_date|escape:'html':'UTF-8'}</small></td>
                    <td>{$c.commit_message|escape:'html':'UTF-8'}</td>
                  </tr>
                {/foreach}
              </tbody>
            </table>
          </div>
        {else}
          <div class="alert alert-info baal-alert" style="margin-top:12px;">
            <i class="icon-info"></i> {l s='Няма синхронизирани комити' mod='bestautoautologhistory'}
          </div>
        {/if}
      </div>
    </div>
  {/if}

  {* Settings *}
  <div class="baal-card baal-panel" style="margin-top:16px;">
    <div class="baal-card-top">
      <div>
        <div class="baal-emp"><i class="icon-cogs"></i> {l s='Настройки' mod='bestautoautologhistory'}</div>
        <div class="baal-muted">{l s='Управление на записа и задържането на логовете' mod='bestautoautologhistory'}</div>
      </div>
    </div>

    <form method="post" action="" style="margin-top:12px;">
      <input type="hidden" name="controller" value="AdminModules" />
      <input type="hidden" name="configure" value="bestautoautologhistory" />
      <input type="hidden" name="token" value="{$admin_token|escape:'html':'UTF-8'}" />
      <input type="hidden" name="baal_tab" value="{$tab|escape:'html':'UTF-8'}" />
      <div class="row">
        <div class="col-md-3">
          <div class="form-group">
            <label>
              <input type="checkbox" name="BAAL_ENABLED" value="1" {if $enabled}checked{/if} />
              {l s='Активирай логовете' mod='bestautoautologhistory'}
            </label>
          </div>
        </div>

        <div class="col-md-3">
          <div class="form-group">
            <label>{l s='Записи на страница' mod='bestautoautologhistory'}</label>
            <input type="number" name="BAAL_PER_PAGE" class="form-control" value="20" min="20" max="20" readonly />
            <p class="help-block">{l s='Фиксирано на 20' mod='bestautoautologhistory'}</p>
          </div>
        </div>

        <div class="col-md-3">
          <div class="form-group">
            <label>{l s='Timeline лимит' mod='bestautoautologhistory'}</label>
            <input type="number" name="BAAL_TIMELINE_LIMIT" class="form-control" value="{$timeline_limit|escape:'html':'UTF-8'}" min="50" max="2000" />
          </div>
        </div>

        <div class="col-md-3">
          <div class="form-group">
            <label>{l s='Задържане (дни)' mod='bestautoautologhistory'}</label>
            <input type="number" name="BAAL_RETENTION_DAYS" class="form-control" value="{$retention_days|escape:'html':'UTF-8'}" min="0" max="3650" />
            <p class="help-block">{l s='0 = без автоматично изчистване' mod='bestautoautologhistory'}</p>
          </div>
        </div>
      </div>

      <button type="submit" name="submitBAALSettings" class="btn btn-primary baal-btn"><i class="icon-save"></i> {l s='Запази настройки' mod='bestautoautologhistory'}</button>
    </form>
  </div>
</div>
