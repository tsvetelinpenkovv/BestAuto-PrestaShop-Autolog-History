{* Git history pagination *}
{if $pages>1}
<div class="ba-pagination">
{section name=p start=1 loop=min($pages,4)+1}
<a href="{$currentIndex}&configure=bestautoautologhistory&token={$token}&git_page={$smarty.section.p.index}"
 class="ba-page {if $smarty.section.p.index==$current}active{/if}">
 {$smarty.section.p.index}
</a>
{/section}
{if $pages>4}
<a href="{$currentIndex}&configure=bestautoautologhistory&token={$token}&git_page={$current+1}">&gt;</a>
{/if}
</div>
{/if}