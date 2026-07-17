@props(['left', 'right' => '', 'bold' => false])
<table class="doc">
    <tr>
        <td style="width:68%;{{ $bold ? ' font-weight:bold' : '' }}">{{ $left }}</td>
        <td class="right" style="width:32%;{{ $bold ? ' font-weight:bold' : '' }}">{{ $right }}</td>
    </tr>
</table>
