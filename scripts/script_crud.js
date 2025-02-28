document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".estatus-select").forEach(select => {
        select.addEventListener("change", function () {
            const id = this.dataset.id;
            const estatus = this.value;

            fetch("crud.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ id, estatus })
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert("Error: " + data.error);
                } else {
                    alert("Estatus actualizado a: " + estatus);
                }
            })
            .catch(error => console.error("Error:", error));
        });
    });
});
