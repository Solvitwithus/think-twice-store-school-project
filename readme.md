<!-- <script>
function addRow() {
    let table = document.getElementById("tableBody");

    let row = `
        <tr>
            <td><input type="text" name="itemName[]"></td>
            <td><input type="number" name="qty[]"></td>
            <td><button type="button" onclick="removeRow(this)">Delete</button></td>
        </tr>
    `;

    table.insertAdjacentHTML("beforeend", row);
}

function removeRow(button) {
    button.closest("tr").remove();
}
</script>

<table border="1" id="itemsTable">
    <thead>
        <tr>
            <th>Item Name</th>
            <th>Qty</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody id="tableBody">
        <tr>
            <td><input type="text" name="itemName[]"></td>
            <td><input type="number" name="qty[]"></td>
            <td><button type="button" onclick="removeRow(this)">Delete</button></td>
        </tr>
    </tbody>
</table>

<button type="button" onclick="addRow()">Add Row</button> -->